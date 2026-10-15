<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\LeaveAccountGrantRebuilder;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use PHPUnit\Framework\TestCase;

/**
 * 特別休暇・代休の付与の変換(論点18 assets/event-rebuild-mapping.md 2.2)。
 */
class LeaveAccountGrantRebuilderTest extends TestCase
{
    private const GRANT = 'grant-1';

    private LeaveAccountGrantRebuilder $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuilder = new LeaveAccountGrantRebuilder;
    }

    public function test_special_grant_and_revocation_move_to_the_users_special_leave_account(): void
    {
        $outcome = $this->rebuilder->convertSpecial(self::GRANT, [
            $this->row(1, 'special_leave.granted', ['userId' => 'u1', 'specialLeaveTypeId' => 3, 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 5.0, 'grantReason' => '付与']),
            $this->row(2, 'special_leave.grant_revoked', ['revokedByUserId' => 'admin', 'reason' => '誤付与']),
        ]);

        $account = UserManagementStreamId::for('special_leave_account', 'u1');
        $this->assertSame('u1', $outcome['userId']);
        $this->assertSame(['special_leave_account.grant_registered', 'special_leave_account.grant_revoked'], array_map(fn ($e) => $e->eventClass, $outcome['events']));
        $this->assertSame($account, $outcome['events'][0]->aggregateUuid);
        $this->assertSame(
            ['grantId' => self::GRANT, 'specialLeaveTypeId' => 3, 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 5.0, 'grantReason' => '付与', 'userId' => 'u1'],
            $outcome['events'][0]->payload,
        );
        $this->assertSame($account, $outcome['events'][1]->aggregateUuid);
        $this->assertSame(['grantId' => self::GRANT, 'revokedByUserId' => 'admin', 'reason' => '誤付与'], $outcome['events'][1]->payload);
        $this->assertSame([1, 2], array_map(fn ($e) => $e->anchorId, $outcome['events']));
    }

    public function test_special_grant_without_creation_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->convertSpecial(self::GRANT, [$this->row(2, 'special_leave.grant_revoked', ['revokedByUserId' => 'admin', 'reason' => null])]);
    }

    public function test_compensatory_grant_lifecycle_takes_the_user_from_the_creation_event(): void
    {
        $outcome = $this->rebuilder->convertCompensatory(self::GRANT, [
            $this->row(1, 'compensatory_leave.grant_synced', ['userId' => 'u1', 'attendanceDayId' => 'day-1', 'workDate' => '2026-08-01', 'grantedDays' => 1.0, 'grantedMinutes' => 480]),
            $this->row(2, 'compensatory_leave.grant_confirmed', ['confirmedAt' => '2026-09-01 10:00:00', 'expiresOn' => '2027-09-01']),
            $this->row(3, 'compensatory_leave.grant_cancelled', ['cancelledByUserId' => 'admin', 'reason' => null]),
        ]);

        $account = UserManagementStreamId::for('compensatory_leave_account', 'u1');
        $this->assertSame(
            ['compensatory_leave_account.grant_synced', 'compensatory_leave_account.grant_confirmed', 'compensatory_leave_account.grant_cancelled'],
            array_map(fn ($e) => $e->eventClass, $outcome['events']),
        );
        foreach ($outcome['events'] as $event) {
            $this->assertSame($account, $event->aggregateUuid);
            $this->assertSame('u1', $event->payload['userId']);
            $this->assertSame(self::GRANT, $event->payload['grantId']);
        }
        $this->assertSame(['userId' => 'u1', 'grantId' => self::GRANT, 'sourceWorkDate' => '2026-08-01', 'grantedDays' => 1.0, 'grantedMinutes' => 480], $outcome['events'][0]->payload);
        $this->assertSame('2027-09-01', $outcome['events'][1]->payload['expiresOn']);
        $this->assertSame('admin', $outcome['events'][2]->payload['cancelledByUserId']);
    }

    public function test_compensatory_manual_grant_and_removal(): void
    {
        $outcome = $this->rebuilder->convertCompensatory(self::GRANT, [
            $this->row(1, 'compensatory_leave.manually_granted', ['userId' => 'u1', 'workDate' => '2026-08-01', 'grantedDays' => 0.5, 'grantedMinutes' => null, 'expiresOn' => null, 'grantReason' => '手動']),
            $this->row(2, 'compensatory_leave.grant_removed', ['reason' => '取り消し']),
        ]);

        $this->assertSame('compensatory_leave_account.grant_manually_granted', $outcome['events'][0]->eventClass);
        $this->assertSame(
            ['userId' => 'u1', 'grantId' => self::GRANT, 'sourceWorkDate' => '2026-08-01', 'grantedDays' => 0.5, 'grantedMinutes' => null, 'expiresOn' => null, 'grantReason' => '手動'],
            $outcome['events'][0]->payload,
        );
        $this->assertSame(['userId' => 'u1', 'grantId' => self::GRANT, 'reason' => '取り消し'], $outcome['events'][1]->payload);
    }

    public function test_compensatory_grant_without_creation_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->convertCompensatory(self::GRANT, [$this->row(2, 'compensatory_leave.grant_removed', ['reason' => 'x'])]);
    }

    public function test_a_row_of_another_aggregate_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->convertSpecial(self::GRANT, [
            $this->row(1, 'special_leave.granted', ['userId' => 'u1', 'specialLeaveTypeId' => 3, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 5.0, 'grantReason' => null]),
            new StoredEventRow(2, 'other-grant', 1, 'special_leave.grant_revoked', ['revokedByUserId' => 'a', 'reason' => null], [], '2026-08-01 10:00:00'),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function row(int $id, string $class, array $payload): StoredEventRow
    {
        return new StoredEventRow($id, self::GRANT, $id, $class, $payload, [], '2026-08-01 10:00:00');
    }
}
