<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\LeaveRequestRebuilder;
use App\Domain\EventSourcing\Rebuild\LeaveUsageId;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use PHPUnit\Framework\TestCase;

/**
 * 特別休暇・代休の申請の状態機械(論点18 assets/event-rebuild-mapping.md 2.1・2.5・5章)の正常・境界・異常。
 * 旧の申請集約(集約ID=申請ID)と付与集約の *.used・*.usage_reversed(申請IDで紐付く)を入力にする。
 */
class LeaveRequestRebuilderTest extends TestCase
{
    private const R = 'req-1';

    private const USER = 'user-1';

    private LeaveRequestRebuilder $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuilder = new LeaveRequestRebuilder;
    }

    // ---- 特別休暇 ----

    public function test_special_approval_with_used_confirms_with_allocations_per_grant_and_fills_null_items(): void
    {
        $result = $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.usage_designated', ['userId' => self::USER, 'attendanceDayId' => 'day-1', 'usedOn' => '2026-08-10', 'usedDays' => 1.0, 'usedMinutes' => null, 'usageType' => 'full']),
            $this->req(12, 'special_leave.request_shared', ['workflowRequestId' => 'wf-1']),
            $this->req(13, 'special_leave.request_approved', ['approvedByUserId' => 'approver-1']),
        ], [$this->used(14, 'grant-1', 0.5), $this->used(15, 'grant-2', 0.5)]);

        $events = $this->ordered($result->events);
        $this->assertSame(
            [
                'special_leave.requested',
                'special_leave_account.usage_designated',
                'special_leave.request_shared',
                'special_leave.request_approved',
                'special_leave_account.usage_confirmed',
            ],
            $this->classes($events),
        );
        $account = UserManagementStreamId::for('special_leave_account', self::USER);
        $usageId = LeaveUsageId::for('special_leave', self::R, 0);

        $designated = $events[1];
        $this->assertSame($account, $designated->aggregateUuid);
        $this->assertSame(11, $designated->anchorId);
        $this->assertSame(
            ['usageId' => $usageId, 'requestId' => self::R, 'specialLeaveTypeId' => 3, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'userId' => self::USER],
            $designated->payload,
        );

        $approved = $events[3];
        $this->assertSame(self::R, $approved->aggregateUuid);
        $this->assertSame(self::USER, $approved->payload['userId']);
        $this->assertSame('2026-08-10', $approved->payload['targetDate']);
        $this->assertTrue($approved->payload['requiresGrant']);
        $this->assertSame(3, $approved->payload['specialLeaveTypeId']);
        $this->assertSame('full', $approved->payload['leaveType']);
        $this->assertSame(1.0, $approved->payload['requestedDays']);
        $this->assertSame('approver-1', $approved->payload['approvedByUserId']);

        $confirmed = $events[4];
        $this->assertSame($account, $confirmed->aggregateUuid);
        $this->assertSame(
            ['usageId' => $usageId, 'allocations' => [['grantId' => 'grant-1', 'allocatedDays' => 0.5], ['grantId' => 'grant-2', 'allocatedDays' => 0.5]], 'unallocatedDays' => 0.0],
            $confirmed->payload,
        );
        $this->assertSame('approved', $result->finalState);
        $this->assertEqualsCanonicalizing([10, 11, 12, 13, 14, 15], $this->sortedIds($result->consumedIds));
    }

    public function test_special_approval_without_used_confirms_without_allocation_and_does_not_require_a_grant(): void
    {
        $result = $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.request_approved', ['approvedByUserId' => 'approver-1']),
        ], []);

        $events = $this->ordered($result->events);
        $this->assertSame(['special_leave.requested', 'special_leave_account.usage_designated', 'special_leave.request_approved', 'special_leave_account.usage_confirmed'], $this->classes($events));
        $this->assertFalse($events[2]->payload['requiresGrant']);
        $this->assertSame([], $events[3]->payload['allocations']);
        $this->assertSame(0.0, $events[3]->payload['unallocatedDays']);
        // 指定の行が旧に無ければ申請から合成する(requestedの直後)。
        $this->assertSame(10, $events[1]->rebuiltFrom);
        $this->assertSame(1, $events[1]->subOrder);
    }

    public function test_special_partial_allocation_records_the_unallocated_days(): void
    {
        $result = $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.request_approved', ['approvedByUserId' => 'approver-1']),
        ], [$this->used(12, 'grant-1', 0.4)]);

        $confirmed = $this->ordered($result->events)[3];
        $this->assertEqualsWithDelta(0.6, $confirmed->payload['unallocatedDays'], 0.000001);
    }

    public function test_special_hours_are_converted_to_minutes_when_the_designation_is_synthesized(): void
    {
        $requested = $this->sRequested(10, ['leaveType' => 'hourly', 'hours' => 2.5, 'requestedDays' => 0.3125]);

        $result = $this->rebuilder->rebuild('special_leave', self::R, [$requested], []);

        $designated = $this->ordered($result->events)[1];
        $this->assertSame(150, $designated->payload['usedMinutes']);
        $this->assertSame('hourly', $designated->payload['usageType']);
    }

    public function test_special_cancel_after_approval_releases_the_reversed_allocations(): void
    {
        $result = $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.request_approved', ['approvedByUserId' => 'approver-1']),
            $this->req(13, 'special_leave.request_cancelled', ['cancelledByUserId' => self::USER, 'reason' => '予定変更']),
        ], [
            $this->used(12, 'grant-1', 1.0),
            $this->reversed(14, 'grant-1', 1.0),
        ]);

        $events = $this->ordered($result->events);
        $this->assertSame(['special_leave.request_cancelled', 'special_leave_account.usage_cancelled'], array_slice($this->classes($events), -2));
        $cancelled = $events[count($events) - 1];
        $this->assertSame([['grantId' => 'grant-1', 'releasedDays' => 1.0]], $cancelled->payload['releasedAllocations']);
        $this->assertSame('予定変更', $cancelled->payload['reason']);
        $this->assertSame('cancelled', $result->finalState);
        $this->assertSame(13, $result->lastRelease['row']->id);
        $this->assertContains(14, $result->consumedIds);
    }

    public function test_special_cancel_without_reversed_rows_releases_all_confirmed_allocations(): void
    {
        $result = $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.request_approved', ['approvedByUserId' => 'approver-1']),
            $this->req(13, 'special_leave.request_cancelled', ['cancelledByUserId' => self::USER]),
        ], [$this->used(12, 'grant-1', 0.5), $this->used(14, 'grant-2', 0.5)]);

        $cancelled = $this->ordered($result->events);
        $cancelled = $cancelled[count($cancelled) - 1];
        $this->assertSame(
            [['grantId' => 'grant-1', 'releasedDays' => 0.5], ['grantId' => 'grant-2', 'releasedDays' => 0.5]],
            $cancelled->payload['releasedAllocations'],
        );
        $this->assertSame('特別休暇申請の取消', $cancelled->payload['reason']);
    }

    public function test_special_cancel_while_pending_cancels_the_usage_without_release(): void
    {
        $result = $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.request_cancelled', ['cancelledByUserId' => self::USER]),
        ], []);

        $cancelled = $this->ordered($result->events);
        $cancelled = $cancelled[count($cancelled) - 1];
        $this->assertSame('special_leave_account.usage_cancelled', $cancelled->eventClass);
        $this->assertSame([], $cancelled->payload['releasedAllocations']);
    }

    public function test_special_return_then_resubmit_then_approve_uses_a_new_usage_id(): void
    {
        $result = $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.request_returned', ['returnedByUserId' => 'approver-1', 'comment' => '直して']),
            $this->req(12, 'special_leave.request_resubmitted', ['resubmittedByUserId' => self::USER, 'approverUserId' => 'approver-2']),
            $this->req(13, 'special_leave.request_approved', ['approvedByUserId' => 'approver-2']),
        ], [$this->used(14, 'grant-1', 1.0)]);

        $events = $this->ordered($result->events);
        $this->assertSame(
            [
                'special_leave.requested',
                'special_leave_account.usage_designated',
                'special_leave.request_returned',
                'special_leave_account.usage_cancelled',
                'special_leave.request_resubmitted',
                'special_leave_account.usage_designated',
                'special_leave.request_approved',
                'special_leave_account.usage_confirmed',
            ],
            $this->classes($events),
        );
        $first = LeaveUsageId::for('special_leave', self::R, 0);
        $second = LeaveUsageId::for('special_leave', self::R, 1);
        $this->assertSame($first, $events[3]->payload['usageId']);
        $this->assertSame('特別休暇申請の差戻しによる消化記録の取消', $events[3]->payload['reason']);
        $this->assertSame($second, $events[5]->payload['usageId']);
        $this->assertSame($second, $events[7]->payload['usageId']);
        // 差戻し・再提出のイベントの既定値null項目が申請の内容から補完される。
        $this->assertSame(self::USER, $events[2]->payload['userId']);
        $this->assertSame('2026-08-10', $events[2]->payload['targetDate']);
        $this->assertSame(self::USER, $events[4]->payload['userId']);
        $this->assertSame('2026-08-10', $events[4]->payload['targetDate']);
        $this->assertSame(3, $events[4]->payload['specialLeaveTypeId']);
        $this->assertSame('full', $events[4]->payload['leaveType']);
        $this->assertSame(1.0, $events[4]->payload['requestedDays']);
        $this->assertSame(1, $result->resubmitCount);
        $this->assertSame('approved', $result->finalState);
    }

    public function test_special_cancel_of_a_returned_request_does_not_cancel_the_usage_twice(): void
    {
        $result = $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.request_returned', ['returnedByUserId' => 'approver-1', 'comment' => 'x']),
            $this->req(12, 'special_leave.request_cancelled', ['cancelledByUserId' => self::USER]),
        ], []);

        $classes = $this->classes($this->ordered($result->events));
        $this->assertSame(1, count(array_keys($classes, 'special_leave_account.usage_cancelled')));
        $this->assertSame('cancelled', $result->finalState);
    }

    public function test_special_used_before_approval_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild('special_leave', self::R, [$this->sRequested(10)], [$this->used(11, 'grant-1', 1.0)]);
    }

    public function test_special_approval_after_cancel_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild('special_leave', self::R, [
            $this->sRequested(10),
            $this->req(11, 'special_leave.request_cancelled', ['cancelledByUserId' => self::USER]),
            $this->req(12, 'special_leave.request_approved', ['approvedByUserId' => 'a']),
        ], []);
    }

    public function test_special_request_without_creation_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild('special_leave', self::R, [$this->req(11, 'special_leave.request_approved', ['approvedByUserId' => 'a'])], []);
    }

    // ---- 代休 ----

    public function test_compensatory_full_day_approval_allocates_days_and_fills_the_applicant(): void
    {
        $result = $this->rebuilder->rebuild('compensatory_leave', self::R, [
            $this->cRequested(10),
            $this->req(11, 'compensatory_leave.request_approved', ['approvedByUserId' => 'approver-1', 'userId' => null]),
        ], [$this->cUsed(12, 'grant-1', 1.0)]);

        $events = $this->ordered($result->events);
        $this->assertSame(
            ['compensatory_leave.requested', 'compensatory_leave_account.usage_designated', 'compensatory_leave.request_approved', 'compensatory_leave_account.usage_confirmed'],
            $this->classes($events),
        );
        $account = UserManagementStreamId::for('compensatory_leave_account', self::USER);
        $usageId = LeaveUsageId::for('compensatory_leave', self::R, 0);
        $this->assertSame(
            ['userId' => self::USER, 'usageId' => $usageId, 'requestId' => self::R, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null],
            $events[1]->payload,
        );
        $this->assertSame(self::USER, $events[2]->payload['userId']);
        $this->assertSame($account, $events[3]->aggregateUuid);
        $this->assertSame(
            ['userId' => self::USER, 'usageId' => $usageId, 'allocations' => [['grantId' => 'grant-1', 'allocatedDays' => 1.0, 'allocatedMinutes' => 0]], 'unallocatedDays' => 0.0, 'unallocatedMinutes' => 0],
            $events[3]->payload,
        );
    }

    public function test_compensatory_hourly_approval_allocates_minutes_and_records_the_unallocated_minutes(): void
    {
        $result = $this->rebuilder->rebuild('compensatory_leave', self::R, [
            $this->cRequested(10, ['leaveType' => 'hourly', 'hours' => 2.0, 'requestedDays' => 0.25, 'requestedMinutes' => 120]),
            $this->req(11, 'compensatory_leave.request_approved', ['approvedByUserId' => 'approver-1']),
        ], [$this->cUsed(12, 'grant-1', 0.0, 90, 'hourly')]);

        $events = $this->ordered($result->events);
        $this->assertSame(120, $events[1]->payload['usedMinutes']);
        $this->assertSame('hourly', $events[1]->payload['usageType']);
        $this->assertSame([['grantId' => 'grant-1', 'allocatedDays' => 0.0, 'allocatedMinutes' => 90]], $events[3]->payload['allocations']);
        $this->assertSame(0.0, $events[3]->payload['unallocatedDays']);
        $this->assertSame(30, $events[3]->payload['unallocatedMinutes']);
    }

    public function test_compensatory_approval_without_used_records_the_whole_request_as_unallocated(): void
    {
        $result = $this->rebuilder->rebuild('compensatory_leave', self::R, [
            $this->cRequested(10),
            $this->req(11, 'compensatory_leave.request_approved', ['approvedByUserId' => 'approver-1']),
        ], []);

        $confirmed = $this->ordered($result->events)[3];
        $this->assertSame([], $confirmed->payload['allocations']);
        $this->assertSame(1.0, $confirmed->payload['unallocatedDays']);
    }

    public function test_compensatory_cancel_releases_days_and_minutes_and_fills_the_applicant(): void
    {
        $result = $this->rebuilder->rebuild('compensatory_leave', self::R, [
            $this->cRequested(10),
            $this->req(11, 'compensatory_leave.request_approved', ['approvedByUserId' => 'approver-1']),
            $this->req(13, 'compensatory_leave.request_cancelled', ['cancelledByUserId' => self::USER, 'userId' => null]),
        ], [$this->cUsed(12, 'grant-1', 1.0), $this->cReversed(14, 'grant-1', 1.0)]);

        $events = $this->ordered($result->events);
        $this->assertSame(self::USER, $events[count($events) - 2]->payload['userId']);
        $cancelled = $events[count($events) - 1];
        $this->assertSame('compensatory_leave_account.usage_cancelled', $cancelled->eventClass);
        $this->assertSame(self::USER, $cancelled->payload['userId']);
        $this->assertSame([['grantId' => 'grant-1', 'releasedDays' => 1.0, 'releasedMinutes' => 0]], $cancelled->payload['releasedAllocations']);
        $this->assertSame('代休申請の取消', $cancelled->payload['reason']);
    }

    public function test_compensatory_return_and_resubmit_fill_the_null_items_and_use_a_new_usage_id(): void
    {
        $result = $this->rebuilder->rebuild('compensatory_leave', self::R, [
            $this->cRequested(10),
            $this->req(11, 'compensatory_leave.request_returned', ['returnedByUserId' => 'approver-1', 'comment' => 'x', 'userId' => null]),
            $this->req(12, 'compensatory_leave.request_resubmitted', ['resubmittedByUserId' => self::USER, 'userId' => null, 'targetDate' => null]),
        ], []);

        $events = $this->ordered($result->events);
        $this->assertSame(self::USER, $events[2]->payload['userId']);
        $resubmitted = $events[4];
        $this->assertSame('compensatory_leave.request_resubmitted', $resubmitted->eventClass);
        $this->assertSame(self::USER, $resubmitted->payload['userId']);
        $this->assertSame('2026-08-10', $resubmitted->payload['targetDate']);
        $this->assertSame('full', $resubmitted->payload['leaveType']);
        $this->assertSame(1.0, $resubmitted->payload['requestedDays']);
        $this->assertSame(LeaveUsageId::for('compensatory_leave', self::R, 1), $events[5]->payload['usageId']);
    }

    /** @param  array<string, mixed>  $override */
    private function sRequested(int $id, array $override = []): StoredEventRow
    {
        return $this->req($id, 'special_leave.requested', $override + [
            'userId' => self::USER,
            'specialLeaveTypeId' => 3,
            'targetDate' => '2026-08-10',
            'leaveType' => 'full',
            'hours' => null,
            'requestedDays' => 1.0,
            'approverUserId' => 'approver-1',
            'reason' => '慶弔',
            'requestGroupId' => null,
        ]);
    }

    /** @param  array<string, mixed>  $override */
    private function cRequested(int $id, array $override = []): StoredEventRow
    {
        return $this->req($id, 'compensatory_leave.requested', $override + [
            'userId' => self::USER,
            'targetDate' => '2026-08-10',
            'leaveType' => 'full',
            'hours' => null,
            'requestedDays' => 1.0,
            'requestedMinutes' => null,
            'approverUserId' => 'approver-1',
            'reason' => '代休',
            'requestGroupId' => null,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function req(int $id, string $class, array $payload): StoredEventRow
    {
        return new StoredEventRow($id, self::R, 1, $class, $payload, ['aggregate-root-version' => 1], '2026-08-01 10:00:00');
    }

    /** 特別休暇の付与集約の消化(申請IDで紐付く)。 */
    private function used(int $id, string $grantId, float $days): StoredEventRow
    {
        return new StoredEventRow($id, $grantId, 1, 'special_leave.used', $this->usagePayload('special_leave', $days, null, 'full'), [], '2026-08-01 10:00:00');
    }

    private function reversed(int $id, string $grantId, float $days): StoredEventRow
    {
        return new StoredEventRow($id, $grantId, 2, 'special_leave.usage_reversed', $this->usagePayload('special_leave', $days, null, 'full'), [], '2026-08-01 10:00:00');
    }

    /** 代休の付与集約の消化。 */
    private function cUsed(int $id, string $grantId, float $days, ?int $minutes = null, string $usageType = 'full'): StoredEventRow
    {
        return new StoredEventRow($id, $grantId, 1, 'compensatory_leave.used', $this->usagePayload('compensatory_leave', $days, $minutes, $usageType), [], '2026-08-01 10:00:00');
    }

    private function cReversed(int $id, string $grantId, float $days): StoredEventRow
    {
        return new StoredEventRow($id, $grantId, 2, 'compensatory_leave.usage_reversed', $this->usagePayload('compensatory_leave', $days, null, 'full'), [], '2026-08-01 10:00:00');
    }

    /** @return array<string, mixed> */
    private function usagePayload(string $kind, float $days, ?int $minutes, string $usageType): array
    {
        return [
            'userId' => self::USER,
            LeaveRequestRebuilder::usageRequestKey($kind) => self::R,
            'attendanceDayId' => 'day-1',
            'usedOn' => '2026-08-10',
            'usedDays' => $days,
            'usedMinutes' => $minutes,
            'usageType' => $usageType,
        ];
    }

    /**
     * @param  list<RebuiltEvent>  $events
     * @return list<RebuiltEvent>
     */
    private function ordered(array $events): array
    {
        usort($events, fn ($a, $b) => [$a->anchorId, $a->subOrder] <=> [$b->anchorId, $b->subOrder]);

        return $events;
    }

    /**
     * @param  list<RebuiltEvent>  $events
     * @return list<string>
     */
    private function classes(array $events): array
    {
        return array_map(fn (RebuiltEvent $event) => $event->eventClass, $events);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sortedIds(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
