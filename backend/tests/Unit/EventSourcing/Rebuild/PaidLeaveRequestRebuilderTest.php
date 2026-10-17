<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\LeaveUsageId;
use App\Domain\EventSourcing\Rebuild\PaidLeaveRequestRebuilder;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use PHPUnit\Framework\TestCase;

/**
 * 有給の申請の状態機械(論点18 assets/event-rebuild-mapping.md 2.1)の正常・境界・異常。
 */
class PaidLeaveRequestRebuilderTest extends TestCase
{
    private const R = 'req-1';

    private const USER = 'user-1';

    private PaidLeaveRequestRebuilder $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuilder = new PaidLeaveRequestRebuilder;
    }

    public function test_approved_with_used_confirms_usage_and_allocates_per_grant(): void
    {
        $requestRows = [
            $this->requested(10),
            $this->row(11, self::R, 'paid_leave.request_shared', ['workflowRequestId' => 'wf-1']),
            $this->row(12, self::R, 'paid_leave.request_approved', ['approvedByUserId' => 'approver-1', 'userId' => null]),
        ];
        $usageRows = [$this->used(13, 'grant-1', 1.0)];

        $result = $this->rebuilder->rebuild(self::R, $requestRows, $usageRows, []);

        $this->assertSame(
            [
                'paid_leave_request.requested',
                'paid_leave_account.usage_designated',
                'paid_leave_request.shared',
                'paid_leave_request.approved',
                'paid_leave_account.usage_confirmed',
                'paid_leave_account.usage_allocated',
            ],
            $this->classes($result->events),
        );
        $this->assertSame('approved', $result->finalState);
        $this->assertSame(0, $result->resubmitCount);
        $this->assertEqualsCanonicalizing([10, 11, 12, 13], $this->sortedIds($result->consumedIds));

        $confirmed = $result->events[4];
        $this->assertSame(LeaveUsageId::for('paid_leave', self::R, 0), $confirmed->payload['usageId']);
        $this->assertSame('approver-1', $confirmed->payload['confirmedByUserId']);
        $this->assertSame(self::USER, $confirmed->aggregateUuid);

        $allocated = $result->events[5];
        $this->assertSame('grant-1', $allocated->payload['grantId']);
        $this->assertSame(1.0, $allocated->payload['allocatedDays']);
        $this->assertSame(13, $allocated->rebuiltFrom);
    }

    public function test_approved_without_used_confirms_usage_without_allocation(): void
    {
        $requestRows = [
            $this->requested(10),
            $this->row(11, self::R, 'paid_leave.request_approved', ['approvedByUserId' => 'approver-1']),
        ];

        $result = $this->rebuilder->rebuild(self::R, $requestRows, [], []);

        $this->assertContains('paid_leave_account.usage_confirmed', $this->classes($result->events));
        $this->assertNotContains('paid_leave_account.usage_allocated', $this->classes($result->events));
        $this->assertSame('approved', $result->finalState);
    }

    public function test_cancel_while_pending_cancels_usage_without_release(): void
    {
        $requestRows = [
            $this->requested(10),
            $this->row(11, self::R, 'paid_leave.request_cancelled', ['cancelledByUserId' => self::USER, 'reason' => null]),
        ];

        $result = $this->rebuilder->rebuild(self::R, $requestRows, [], []);

        $this->assertSame(
            ['paid_leave_request.requested', 'paid_leave_account.usage_designated', 'paid_leave_request.cancelled', 'paid_leave_account.usage_cancelled'],
            $this->classes($result->events),
        );
        $this->assertSame('有給申請の取消', $result->events[3]->payload['reason']);
        $this->assertSame('cancelled', $result->finalState);
    }

    public function test_cancel_after_approval_releases_each_allocation_before_cancelling(): void
    {
        $requestRows = [
            $this->requested(10),
            $this->row(11, self::R, 'paid_leave.request_approved', ['approvedByUserId' => 'approver-1']),
            $this->row(12, self::R, 'paid_leave.request_cancelled', ['cancelledByUserId' => self::USER]),
        ];
        $usageRows = [$this->used(13, 'grant-1', 0.5), $this->used(14, 'grant-2', 0.5)];

        $result = $this->rebuilder->rebuild(self::R, $requestRows, $usageRows, []);

        $classes = $this->classes($result->events);
        $this->assertSame(
            [
                'paid_leave_account.usage_allocation_released',
                'paid_leave_account.usage_allocation_released',
                'paid_leave_account.usage_cancelled',
            ],
            array_slice($classes, -3),
        );
        $this->assertSame(0.5, $result->events[array_search('paid_leave_account.usage_allocation_released', $classes, true)]->payload['releasedDays']);
    }

    public function test_return_then_resubmit_then_approve_uses_a_new_usage_id(): void
    {
        $requestRows = [
            $this->requested(10),
            $this->row(11, self::R, 'paid_leave.request_shared', ['workflowRequestId' => 'wf-1']),
            $this->row(12, self::R, 'paid_leave.request_returned', ['returnedByUserId' => 'approver-1', 'comment' => '日付を直して']),
        ];
        $workflowRows = [
            $this->row(13, 'wf-1', 'workflow_request.submitted', ['approverUserId' => 'approver-2', 'submittedByUserId' => self::USER]),
            $this->row(14, 'wf-1', 'workflow_request.approved', ['approvedByUserId' => 'approver-2']),
        ];

        $result = $this->rebuilder->rebuild(self::R, $requestRows, [], $workflowRows);

        $classes = $this->classes($result->events);
        $this->assertContains('paid_leave_request.returned', $classes);
        $this->assertContains('paid_leave_request.resubmitted', $classes);
        $this->assertSame('approved', $result->finalState);
        $this->assertSame(1, $result->resubmitCount);

        $cancelled = $result->events[array_search('paid_leave_account.usage_cancelled', $classes, true)];
        $this->assertSame(LeaveUsageId::for('paid_leave', self::R, 0), $cancelled->payload['usageId']);
        $this->assertSame('差戻しによる取消', $cancelled->payload['reason']);

        $designatedAgain = $result->events[array_keys($classes, 'paid_leave_account.usage_designated')[1]];
        $this->assertSame(LeaveUsageId::for('paid_leave', self::R, 1), $designatedAgain->payload['usageId']);
        $this->assertSame('approver-2', $designatedAgain->payload['approverUserId']);

        $confirmed = $result->events[array_search('paid_leave_account.usage_confirmed', $classes, true)];
        $this->assertSame(LeaveUsageId::for('paid_leave', self::R, 1), $confirmed->payload['usageId']);
    }

    public function test_workflow_rejection_without_request_cancel_is_treated_as_cancellation(): void
    {
        $requestRows = [$this->requested(10)];
        $workflowRows = [
            $this->row(11, 'wf-1', 'workflow_request.rejected', ['rejectedByUserId' => 'approver-1', 'reason' => '人員配置']),
        ];

        $requestRows[] = $this->row(11, self::R, 'paid_leave.request_shared', ['workflowRequestId' => 'wf-1']);
        $result = $this->rebuilder->rebuild(self::R, $requestRows, [], $workflowRows);

        $this->assertSame('cancelled', $result->finalState);
        $cancelled = $result->events[array_search('paid_leave_request.cancelled', $this->classes($result->events), true)];
        $this->assertSame('approver-1', $cancelled->payload['cancelledByUserId']);
        $this->assertSame('人員配置', $cancelled->payload['reason']);
    }

    public function test_usage_before_any_approval_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild(self::R, [$this->requested(10)], [$this->used(11, 'grant-1', 1.0)], []);
    }

    public function test_cancel_twice_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild(self::R, [
            $this->requested(10),
            $this->row(11, self::R, 'paid_leave.request_cancelled', ['cancelledByUserId' => self::USER]),
            $this->row(12, self::R, 'paid_leave.request_cancelled', ['cancelledByUserId' => self::USER]),
        ], [], []);
    }

    public function test_missing_request_creation_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild(self::R, [$this->row(11, self::R, 'paid_leave.request_approved', ['approvedByUserId' => 'a'])], [], []);
    }

    // ---- cutover後(申請の記録先が口座イベント)の有給 ----

    public function test_account_designation_adds_requested_and_shared_before_it(): void
    {
        $result = $this->rebuilder->rebuildFromAccount(self::R, [$this->designated(10, 'wf-1')], []);

        $events = $this->ordered($result->events);
        $this->assertSame(
            ['paid_leave_request.requested', 'paid_leave_request.shared', 'paid_leave_account.usage_designated'],
            $this->classes($events),
        );
        $this->assertSame(self::R, $events[0]->aggregateUuid);
        $this->assertSame(self::USER, $events[0]->payload['userId']);
        $this->assertSame('2026-08-10', $events[0]->payload['targetDate']);
        $this->assertSame('full', $events[0]->payload['leaveType']);
        $this->assertSame(1.0, $events[0]->payload['requestedDays']);
        $this->assertSame('wf-1', $events[0]->payload['workflowRequestId']);
        $this->assertSame('wf-1', $events[1]->payload['workflowRequestId']);
        // 元の指定はそのまま(乱数のusageIdを引き継ぐ)。
        $this->assertSame('u-0', $events[2]->payload['usageId']);
        $this->assertSame('shared', $result->finalState);
        $this->assertSame([10], $this->sortedIds($result->consumedIds));
        $this->assertSame(self::USER, $result->userId);
        $this->assertSame('2026-08-10', $result->targetDate);
    }

    public function test_account_designation_without_workflow_adds_only_requested(): void
    {
        $result = $this->rebuilder->rebuildFromAccount(self::R, [$this->designated(10, null)], []);

        $this->assertSame(
            ['paid_leave_request.requested', 'paid_leave_account.usage_designated'],
            $this->classes($this->ordered($result->events)),
        );
        $this->assertSame('requested', $result->finalState);
    }

    public function test_account_confirmation_adds_approved_before_it_and_keeps_allocations(): void
    {
        $result = $this->rebuilder->rebuildFromAccount(self::R, [
            $this->designated(10, 'wf-1'),
            $this->acct(11, 'paid_leave_account.usage_confirmed', ['usageId' => 'u-0', 'confirmedByUserId' => 'approver-1']),
            $this->acct(12, 'paid_leave_account.usage_allocated', ['usageId' => 'u-0', 'grantId' => 'grant-1', 'allocatedDays' => 1.0]),
        ], []);

        $events = $this->ordered($result->events);
        $this->assertSame(
            [
                'paid_leave_request.requested',
                'paid_leave_request.shared',
                'paid_leave_account.usage_designated',
                'paid_leave_request.approved',
                'paid_leave_account.usage_confirmed',
                'paid_leave_account.usage_allocated',
            ],
            $this->classes($events),
        );
        $this->assertSame('approver-1', $events[3]->payload['approvedByUserId']);
        $this->assertSame(self::USER, $events[3]->payload['userId']);
        $this->assertSame(self::R, $events[3]->aggregateUuid);
        $this->assertSame('approved', $result->finalState);
    }

    public function test_account_cancel_while_pending_adds_request_cancelled_before_the_usage_cancel(): void
    {
        $result = $this->rebuilder->rebuildFromAccount(self::R, [
            $this->designated(10, null),
            $this->acct(11, 'paid_leave_account.usage_cancelled', ['usageId' => 'u-0', 'cancelledByUserId' => self::USER, 'reason' => '都合']),
        ], []);

        $events = $this->ordered($result->events);
        $this->assertSame(
            ['paid_leave_request.requested', 'paid_leave_account.usage_designated', 'paid_leave_request.cancelled', 'paid_leave_account.usage_cancelled'],
            $this->classes($events),
        );
        $this->assertSame(self::USER, $events[2]->payload['cancelledByUserId']);
        $this->assertSame('都合', $events[2]->payload['reason']);
        $this->assertSame('cancelled', $result->finalState);
        $this->assertSame(11, $result->lastRelease['row']->id);
    }

    public function test_account_cancel_after_approval_does_not_release_again_when_the_account_already_released(): void
    {
        $result = $this->rebuilder->rebuildFromAccount(self::R, [
            $this->designated(10, null),
            $this->acct(11, 'paid_leave_account.usage_confirmed', ['usageId' => 'u-0', 'confirmedByUserId' => 'approver-1']),
            $this->acct(12, 'paid_leave_account.usage_allocated', ['usageId' => 'u-0', 'grantId' => 'grant-1', 'allocatedDays' => 1.0]),
            $this->acct(13, 'paid_leave_account.usage_allocation_released', ['usageId' => 'u-0', 'grantId' => 'grant-1', 'releasedDays' => 1.0]),
            $this->acct(14, 'paid_leave_account.usage_cancelled', ['usageId' => 'u-0', 'cancelledByUserId' => self::USER, 'reason' => null]),
        ], []);

        $classes = $this->classes($this->ordered($result->events));
        $this->assertSame(1, count(array_keys($classes, 'paid_leave_account.usage_allocation_released')));
        $this->assertSame(['paid_leave_request.cancelled', 'paid_leave_account.usage_cancelled'], array_slice($classes, -2));
    }

    public function test_workflow_return_after_designation_adds_returned_and_cancels_the_usage(): void
    {
        $result = $this->rebuilder->rebuildFromAccount(self::R, [$this->designated(10, 'wf-1')], [
            $this->row(11, 'wf-1', 'workflow_request.returned', ['returnedByUserId' => 'approver-1', 'comment' => '日付を直して']),
        ]);

        $events = $this->ordered($result->events);
        $this->assertSame(
            [
                'paid_leave_request.requested',
                'paid_leave_request.shared',
                'paid_leave_account.usage_designated',
                'paid_leave_request.returned',
                'paid_leave_account.usage_cancelled',
            ],
            $this->classes($events),
        );
        $this->assertSame('日付を直して', $events[3]->payload['comment']);
        $this->assertSame('u-0', $events[4]->payload['usageId']);
        $this->assertSame('差戻しによる取消', $events[4]->payload['reason']);
        $this->assertSame('approver-1', $events[4]->payload['cancelledByUserId']);
        $this->assertSame('returned', $result->finalState);
        // ワークフローの行は読むだけ(写すのは呼び出し側)。
        $this->assertNotContains(11, $result->consumedIds);
    }

    public function test_resubmission_after_return_uses_a_new_usage_id_and_rewrites_later_usage_ids(): void
    {
        $result = $this->rebuilder->rebuildFromAccount(self::R, [
            $this->designated(10, 'wf-1'),
            $this->acct(14, 'paid_leave_account.usage_confirmed', ['usageId' => 'u-0', 'confirmedByUserId' => 'approver-2']),
            $this->acct(15, 'paid_leave_account.usage_allocated', ['usageId' => 'u-0', 'grantId' => 'grant-1', 'allocatedDays' => 1.0]),
        ], [
            $this->row(11, 'wf-1', 'workflow_request.submitted', ['approverUserId' => 'approver-1', 'submittedByUserId' => self::USER]),
            $this->row(12, 'wf-1', 'workflow_request.returned', ['returnedByUserId' => 'approver-1', 'comment' => 'x']),
            $this->row(13, 'wf-1', 'workflow_request.submitted', ['approverUserId' => 'approver-2', 'submittedByUserId' => self::USER]),
        ]);

        $events = $this->ordered($result->events);
        $this->assertSame(
            [
                'paid_leave_request.requested',
                'paid_leave_request.shared',
                'paid_leave_account.usage_designated',
                'paid_leave_request.returned',
                'paid_leave_account.usage_cancelled',
                'paid_leave_request.resubmitted',
                'paid_leave_account.usage_designated',
                'paid_leave_request.approved',
                'paid_leave_account.usage_confirmed',
                'paid_leave_account.usage_allocated',
            ],
            $this->classes($events),
        );
        $newUsage = LeaveUsageId::for('paid_leave', self::R, 1);
        $this->assertSame('u-0', $events[4]->payload['usageId']);
        $this->assertSame($newUsage, $events[6]->payload['usageId']);
        $this->assertSame('approver-2', $events[6]->payload['approverUserId']);
        $this->assertSame('approver-2', $events[5]->payload['approverUserId']);
        $this->assertSame($newUsage, $events[8]->payload['usageId']);
        $this->assertSame($newUsage, $events[9]->payload['usageId']);
        $this->assertSame('approved', $result->finalState);
        $this->assertSame(1, $result->resubmitCount);
        $this->assertEqualsCanonicalizing([10, 14, 15], $this->sortedIds($result->consumedIds));
    }

    public function test_workflow_rejection_while_pending_is_treated_as_cancellation(): void
    {
        $result = $this->rebuilder->rebuildFromAccount(self::R, [$this->designated(10, 'wf-1')], [
            $this->row(11, 'wf-1', 'workflow_request.rejected', ['rejectedByUserId' => 'approver-1', 'reason' => '人員配置']),
        ]);

        $events = $this->ordered($result->events);
        $this->assertSame(['paid_leave_request.cancelled', 'paid_leave_account.usage_cancelled'], array_slice($this->classes($events), -2));
        $this->assertSame('approver-1', $events[3]->payload['cancelledByUserId']);
        $this->assertSame('人員配置', $events[3]->payload['reason']);
        $this->assertSame('人員配置', $events[4]->payload['reason']);
        $this->assertSame('cancelled', $result->finalState);
    }

    public function test_usage_cancel_of_a_returned_request_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuildFromAccount(self::R, [
            $this->designated(10, 'wf-1'),
            $this->acct(12, 'paid_leave_account.usage_cancelled', ['usageId' => 'u-0', 'cancelledByUserId' => self::USER, 'reason' => null]),
        ], [
            $this->row(11, 'wf-1', 'workflow_request.returned', ['returnedByUserId' => 'approver-1', 'comment' => 'x']),
        ]);
    }

    public function test_second_designation_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuildFromAccount(self::R, [$this->designated(10, null), $this->designated(11, null)], []);
    }

    public function test_account_confirmation_before_designation_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuildFromAccount(self::R, [
            $this->acct(10, 'paid_leave_account.usage_confirmed', ['usageId' => 'u-0', 'confirmedByUserId' => 'a']),
        ], []);
    }

    public function test_old_style_cancel_records_the_release_for_the_empty_day_rule(): void
    {
        $result = $this->rebuilder->rebuild(self::R, [
            $this->requested(10),
            $this->row(11, self::R, 'paid_leave.request_cancelled', ['cancelledByUserId' => self::USER]),
        ], [], []);

        $this->assertSame(11, $result->lastRelease['row']->id);
        $this->assertSame(self::USER, $result->lastRelease['operatorUserId']);
        $this->assertSame('2026-08-10', $result->targetDate);
    }

    /** cutover後の口座の消化の指定(申請ID=R、利用者=USER)。 */
    private function designated(int $id, ?string $workflowId): StoredEventRow
    {
        return $this->acct($id, 'paid_leave_account.usage_designated', [
            'usageId' => 'u-0',
            'workflowRequestId' => $workflowId,
            'attendanceDayId' => 'day-1',
            'usedOn' => '2026-08-10',
            'usedDays' => 1.0,
            'usageType' => 'full',
            'paidLeaveRequestId' => self::R,
            'approverUserId' => 'approver-1',
            'reason' => '私用',
            'requestGroupId' => null,
            'hours' => null,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function acct(int $id, string $class, array $payload): StoredEventRow
    {
        return $this->row($id, self::USER, $class, $payload);
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

    private function requested(int $id): StoredEventRow
    {
        return $this->row($id, self::R, 'paid_leave.requested', [
            'userId' => self::USER,
            'targetDate' => '2026-08-10',
            'leaveType' => 'full',
            'hours' => null,
            'requestedDays' => 1.0,
            'approverUserId' => 'approver-1',
            'reason' => '私用',
            'requestGroupId' => null,
        ]);
    }

    private function used(int $id, string $grantId, float $days): StoredEventRow
    {
        return $this->row($id, $grantId, 'paid_leave.used', [
            'userId' => self::USER,
            'paidLeaveRequestId' => self::R,
            'attendanceDayId' => 'day-1',
            'usedOn' => '2026-08-10',
            'usedDays' => $days,
            'usedMinutes' => null,
            'usageType' => 'full',
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function row(int $id, string $aggregate, string $class, array $payload): StoredEventRow
    {
        return new StoredEventRow($id, $aggregate, 1, $class, $payload, ['aggregate-root-version' => 1], '2026-08-01 10:00:00');
    }

    /**
     * @param  list<RebuiltEvent>  $events
     * @return list<string>
     */
    private function classes(array $events): array
    {
        return array_map(fn ($event) => $event->eventClass, $events);
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
