<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\CutoverUsageAllocator;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use PHPUnit\Framework\TestCase;

/**
 * cutover前に承認され充当の無い有給の消化記録を cutover の付与に充当する規則(論点19 決定9-3a)。
 */
class CutoverUsageAllocatorTest extends TestCase
{
    private const USER = 'u1';

    private CutoverUsageAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new CutoverUsageAllocator;
    }

    public function test_confirmed_usage_before_cutover_is_allocated_to_the_cutover_grant_right_after_the_later_event(): void
    {
        $grant = $this->grant(9, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $result = $this->allocator->allocate(
            [...$this->usage('U1', '2026-07-10', 1.0, 3), $grant],
            $this->cutovers([$grant]),
        );

        $this->assertCount(1, $result['events']);
        $event = $result['events'][0];
        $this->assertSame('paid_leave_account.usage_allocated', $event->eventClass);
        $this->assertSame(self::USER, $event->aggregateUuid);
        $this->assertSame(['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 1.0], $event->payload);
        // 付与(anchor 9)の方が後ろ。その anchor の、付与より後ろに置く。
        $this->assertSame(9, $event->anchorId);
        $this->assertSame(CutoverUsageAllocator::SUB_ORDER_BASE, $event->subOrder);
        $this->assertSame($grant->createdAt, $event->createdAt);
        $this->assertSame(1, $result['report']['allocated_usages']);
        $this->assertEqualsWithDelta(1.0, $result['report']['allocated_days'], 0.000001);
        $this->assertSame(0, $result['report']['unallocated_usages']);
    }

    public function test_allocation_is_placed_after_the_confirmation_when_it_comes_after_the_grant(): void
    {
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $usage = $this->usage('U1', '2026-07-10', 1.0, 5);
        $result = $this->allocator->allocate([...$usage, $grant], $this->cutovers([$grant]));

        $this->assertSame(5, $result['events'][0]->anchorId);
        $this->assertSame(CutoverUsageAllocator::SUB_ORDER_BASE, $result['events'][0]->subOrder);
        $this->assertSame($usage[1]->createdAt, $result['events'][0]->createdAt);
    }

    public function test_shortage_leaves_the_rest_unallocated_and_reports_it(): void
    {
        $grant = $this->grant(9, 0, 'G', '2026-04-01', '2027-03-31', 0.5);
        $result = $this->allocator->allocate(
            [...$this->usage('U1', '2026-07-10', 1.0, 3), $grant],
            $this->cutovers([$grant]),
        );

        $this->assertCount(1, $result['events']);
        $this->assertEqualsWithDelta(0.5, $result['events'][0]->payload['allocatedDays'], 0.000001);
        $this->assertSame(1, $result['report']['unallocated_usages']);
        $this->assertEqualsWithDelta(0.5, $result['report']['unallocated_days'], 0.000001);
        $this->assertSame('U1', $result['report']['unallocated'][0]['usage_id']);
    }

    public function test_grant_expired_before_the_usage_date_is_not_allocated(): void
    {
        $grant = $this->grant(9, 0, 'G', '2025-04-01', '2026-06-30', 5.0);
        $result = $this->allocator->allocate(
            [...$this->usage('U1', '2026-07-10', 1.0, 3), $grant],
            $this->cutovers([$grant]),
        );

        $this->assertSame([], $result['events']);
        $this->assertSame(0, $result['report']['allocated_usages']);
        $this->assertSame(1, $result['report']['unallocated_usages']);
        $this->assertEqualsWithDelta(1.0, $result['report']['unallocated_days'], 0.000001);
    }

    public function test_multiple_grants_are_used_in_expiry_order_and_each_remaining_is_respected_across_usages(): void
    {
        $late = $this->grant(9, 0, 'LATE', '2026-04-01', '2027-03-31', 2.0);
        $early = $this->grant(9, 1, 'EARLY', '2026-04-01', '2026-12-31', 1.0);
        $result = $this->allocator->allocate(
            [...$this->usage('U1', '2026-07-10', 1.5, 3), ...$this->usage('U2', '2026-07-11', 1.5, 4), $late, $early],
            $this->cutovers([$late, $early]),
        );

        $payloads = array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']);
        $this->assertEqualsCanonicalizing([
            ['usageId' => 'U1', 'grantId' => 'EARLY', 'allocatedDays' => 1.0],
            ['usageId' => 'U1', 'grantId' => 'LATE', 'allocatedDays' => 0.5],
            ['usageId' => 'U2', 'grantId' => 'LATE', 'allocatedDays' => 1.5],
        ], $payloads);
        // 同じ anchor の中は subOrder が重ならない。
        $subs = array_map(fn (RebuiltEvent $e) => $e->subOrder, $result['events']);
        $this->assertSame(count($subs), count(array_unique($subs)));
        $this->assertSame(0, $result['report']['unallocated_usages']);
    }

    public function test_usage_confirmed_after_cutover_or_already_allocated_is_out_of_scope(): void
    {
        $grant = $this->grant(9, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $after = $this->usage('U1', '2026-08-10', 1.0, 11, '2026-08-05 10:00:00');
        $allocated = $this->usage('U2', '2026-07-10', 1.0, 3, '2026-07-12 10:00:00', true);
        $cancelled = $this->usage('U3', '2026-07-10', 1.0, 4, '2026-07-12 10:00:00', false, true);
        $result = $this->allocator->allocate([...$after, ...$allocated, ...$cancelled, $grant], $this->cutovers([$grant]));

        $this->assertSame([], $result['events']);
        $this->assertSame(0, $result['report']['eligible_usages']);
        $this->assertSame(0, $result['report']['unallocated_usages']);
    }

    public function test_rebuilder_places_the_allocation_after_the_carried_over_grant_and_reports_it(): void
    {
        $at = '2026-07-20 10:00:00';
        $result = (new EventStoreRebuilder)->rebuild([
            new StoredEventRow(1, 'R1', 1, 'paid_leave.requested', ['userId' => self::USER, 'targetDate' => '2026-07-21', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null], [], $at),
            new StoredEventRow(2, 'R1', 2, 'paid_leave.request_approved', ['approvedByUserId' => 'a1'], [], $at),
            new StoredEventRow(3, self::USER, 1, 'paid_leave_account.migrated', [
                'cutoverDate' => '2026-08-01',
                'grants' => [
                    ['grantId' => 'G2', 'originalGrantedOn' => null, 'originalGrantedDays' => 5.0, 'remainingDaysAtCutover' => 5.0, 'expiresOn' => '2027-03-31', 'source' => 'migration', 'cutoverMetadata' => ['mode' => 'carry', 'notes' => null]],
                ],
            ], [], '2026-08-01 09:00:00'),
        ]);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(
            [
                'paid_leave_request.requested',
                'paid_leave_account.usage_designated',
                'paid_leave_request.approved',
                'paid_leave_account.usage_confirmed',
                'paid_leave_account.grant_created',
                'paid_leave_account.usage_allocated',
            ],
            array_column($result->rows, 'event_class'),
        );
        $allocated = $result->rows[5];
        $this->assertSame('G2', $allocated['event_properties']['grantId']);
        $this->assertEqualsWithDelta(1.0, $allocated['event_properties']['allocatedDays'], 0.000001);
        $this->assertSame(self::USER, $allocated['aggregate_uuid']);
        $this->assertSame([1, 2, 3, 4], array_column(array_filter($result->rows, fn (array $r) => $r['aggregate_uuid'] === self::USER), 'aggregate_version'));
        $this->assertSame(1, $result->report['cutover_allocation']['allocated_usages']);
        $this->assertSame(0, $result->report['cutover_allocation']['unallocated_usages']);
    }

    /**
     * 消化の指定と確定のイベント(確定は anchor $confirmAnchor)。
     *
     * @return list<RebuiltEvent> [designated, confirmed, ...(allocated), ...(cancelled)]
     */
    private function usage(string $usageId, string $usedOn, float $days, int $confirmAnchor, string $confirmedAt = '2026-07-12 10:00:00', bool $allocated = false, bool $cancelled = false): array
    {
        $events = [
            $this->event('paid_leave_account.usage_designated', ['usageId' => $usageId, 'usedOn' => $usedOn, 'usedDays' => $days], $confirmAnchor - 1, 0, $confirmedAt),
            $this->event('paid_leave_account.usage_confirmed', ['usageId' => $usageId, 'confirmedByUserId' => 'a1'], $confirmAnchor, 1, $confirmedAt),
        ];
        if ($allocated) {
            $events[] = $this->event('paid_leave_account.usage_allocated', ['usageId' => $usageId, 'grantId' => 'X', 'allocatedDays' => $days], $confirmAnchor, 2, $confirmedAt);
        }
        if ($cancelled) {
            $events[] = $this->event('paid_leave_account.usage_cancelled', ['usageId' => $usageId, 'cancelledByUserId' => 'a1', 'reason' => null], $confirmAnchor + 50, 0, $confirmedAt);
        }

        return $events;
    }

    private function grant(int $anchor, int $sub, string $grantId, string $grantedOn, string $expiresOn, float $days): RebuiltEvent
    {
        return $this->event('paid_leave_account.grant_created', [
            'grantId' => $grantId, 'grantedOn' => $grantedOn, 'expiresOn' => $expiresOn, 'grantedDays' => $days, 'grantReason' => null, 'source' => 'carried_over',
        ], $anchor, $sub, '2026-08-01 09:00:00');
    }

    /** @param  list<RebuiltEvent>  $grants */
    private function cutovers(array $grants): array
    {
        return [self::USER => ['cutoverDate' => '2026-08-01', 'grants' => $grants]];
    }

    /** @param  array<string, mixed>  $payload */
    private function event(string $class, array $payload, int $anchor, int $sub, string $at): RebuiltEvent
    {
        return new RebuiltEvent($class, self::USER, $payload, [], $at, $anchor, $sub, null, null);
    }
}
