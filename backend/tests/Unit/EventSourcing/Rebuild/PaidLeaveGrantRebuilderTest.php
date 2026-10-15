<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\PaidLeaveGrantRebuilder;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use PHPUnit\Framework\TestCase;

/**
 * 有給の付与の変換と cutover の突き合わせ(論点18 assets/event-rebuild-mapping.md 2.2・2.3)。
 */
class PaidLeaveGrantRebuilderTest extends TestCase
{
    private const USER = 'user-1';

    private PaidLeaveGrantRebuilder $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuilder = new PaidLeaveGrantRebuilder;
    }

    public function test_granted_becomes_grant_created_with_manual_source_on_the_user_stream(): void
    {
        $granted = $this->row(1, 'grant-1', 'paid_leave.granted', [
            'userId' => self::USER,
            'grantedOn' => '2026-04-01',
            'expiresOn' => '2027-03-31',
            'grantedDays' => 10.0,
            'grantReason' => '入社6か月',
        ]);

        $events = $this->rebuilder->convertGrant('grant-1', self::USER, [$granted]);

        $this->assertCount(1, $events);
        $this->assertSame('paid_leave_account.grant_created', $events[0]->eventClass);
        $this->assertSame(self::USER, $events[0]->aggregateUuid);
        $this->assertSame('manual', $events[0]->payload['source']);
        $this->assertSame('grant-1', $events[0]->payload['grantId']);
    }

    public function test_revoke_and_warning_keep_the_grant_id(): void
    {
        $rows = [
            $this->row(1, 'grant-1', 'paid_leave.granted', ['userId' => self::USER, 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 10.0, 'grantReason' => null]),
            $this->row(2, 'grant-1', 'paid_leave.warning_raised', ['userId' => self::USER, 'warningType' => 'expiry', 'message' => 'm']),
            $this->row(3, 'grant-1', 'paid_leave.grant_revoked', ['revokedByUserId' => 'admin', 'reason' => 'r']),
        ];

        $events = $this->rebuilder->convertGrant('grant-1', self::USER, $rows);

        $this->assertSame('paid_leave_account.grant_warning_raised', $events[1]->eventClass);
        $this->assertSame('grant-1', $events[1]->payload['grantId']);
        $this->assertSame('paid_leave_account.grant_revoked', $events[2]->eventClass);
        $this->assertSame('grant-1', $events[2]->payload['grantId']);
    }

    public function test_cutover_grant_reproduced_by_old_events_adds_no_row(): void
    {
        $granted = $this->row(1, 'grant-1', 'paid_leave.granted', ['userId' => self::USER, 'grantedDays' => 10.0]);
        $migrated = $this->migrated(50, [$this->cutoverGrant('grant-1', 6.0)]);

        $result = $this->rebuilder->reconcileCutover($migrated, [
            'grant-1' => ['granted' => $granted, 'userId' => self::USER, 'used' => 4.0, 'reversed' => 0.0],
        ]);

        $this->assertSame([], $result['events']);
        $this->assertSame('reproduced', $result['outcomes'][0]['outcome']);
    }

    public function test_cutover_reversal_is_counted_back_into_the_remaining_days(): void
    {
        $granted = $this->row(1, 'grant-1', 'paid_leave.granted', ['userId' => self::USER, 'grantedDays' => 10.0]);
        $migrated = $this->migrated(50, [$this->cutoverGrant('grant-1', 8.0)]);

        $result = $this->rebuilder->reconcileCutover($migrated, [
            'grant-1' => ['granted' => $granted, 'userId' => self::USER, 'used' => 3.0, 'reversed' => 1.0],
        ]);

        $this->assertSame([], $result['events']);
    }

    public function test_cutover_grant_not_reproducible_becomes_carried_over(): void
    {
        $migrated = $this->migrated(50, [$this->cutoverGrant('imported-1', 7.5, originalGrantedOn: null)]);

        $result = $this->rebuilder->reconcileCutover($migrated, []);

        $this->assertCount(1, $result['events']);
        $event = $result['events'][0];
        $this->assertSame('paid_leave_account.grant_created', $event->eventClass);
        $this->assertSame(self::USER, $event->aggregateUuid);
        $this->assertSame('imported-1', $event->payload['grantId']);
        $this->assertSame('carried_over', $event->payload['source']);
        $this->assertSame(7.5, $event->payload['grantedDays']);
        $this->assertSame('2026-08-01', $event->payload['grantedOn']);
        $this->assertSame(50, $event->rebuiltFrom);
        $this->assertSame('migration_notes', $event->meta['cutover']['notes']);
    }

    public function test_cutover_grant_with_same_id_but_different_remaining_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $granted = $this->row(1, 'grant-1', 'paid_leave.granted', ['userId' => self::USER, 'grantedDays' => 10.0]);
        $migrated = $this->migrated(50, [$this->cutoverGrant('grant-1', 6.0)]);

        $this->rebuilder->reconcileCutover($migrated, [
            'grant-1' => ['granted' => $granted, 'userId' => self::USER, 'used' => 3.0, 'reversed' => 0.0],
        ]);
    }

    /** @return array<string, mixed> */
    private function cutoverGrant(string $grantId, float $remaining, ?string $originalGrantedOn = '2026-04-01'): array
    {
        return [
            'grantId' => $grantId,
            'originalGrantedOn' => $originalGrantedOn,
            'originalGrantedDays' => 10.0,
            'remainingDaysAtCutover' => $remaining,
            'expiresOn' => '2027-03-31',
            'source' => 'migration',
            'cutoverMetadata' => ['mode' => 'carry', 'notes' => 'migration_notes'],
        ];
    }

    /** @param  list<array<string, mixed>>  $grants */
    private function migrated(int $id, array $grants): StoredEventRow
    {
        return new StoredEventRow($id, self::USER, 1, 'paid_leave_account.migrated', [
            'cutoverDate' => '2026-08-01',
            'grants' => $grants,
        ], [], '2026-08-01 09:00:00');
    }

    /** @param  array<string, mixed>  $payload */
    private function row(int $id, string $aggregate, string $class, array $payload): StoredEventRow
    {
        return new StoredEventRow($id, $aggregate, 1, $class, $payload, [], '2026-04-01 10:00:00');
    }
}
