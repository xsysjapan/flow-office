<?php

namespace Tests\Unit\PaidLeaveRequest;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleMigrated;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleShared;
use Tests\TestCase;

/**
 * PaidLeaveRequestAggregateの単体テスト。すべてEvent→Aggregate replay→Commandの結果を
 * 検証する形式で行い、Projection(Eloquent)・DBは一切使わない。
 */
class PaidLeaveRequestAggregateTest extends TestCase
{
    private const REQUEST_ID = 'request-1';

    // ---- 申請(request) ----

    public function test_request_records_requested_event(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->request(
                    userId: 'user-1',
                    targetDate: '2026-10-12',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: 'approver-1',
                    reason: '私用',
                    requestGroupId: 'group-1',
                    workflowRequestId: null,
                );
            })
            ->assertRecorded([
                new PaidLeaveRequestLifecycleRequested(
                    userId: 'user-1',
                    targetDate: '2026-10-12',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: 'approver-1',
                    reason: '私用',
                    requestGroupId: 'group-1',
                    workflowRequestId: null,
                ),
            ]);
    }

    public function test_request_sets_status_to_submitted(): void
    {
        $this->assertSame('submitted', $this->statusAfter([$this->requested()]));
    }

    public function test_initial_status_is_none(): void
    {
        $this->assertSame('none', $this->statusAfter([]));
    }

    public function test_second_request_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->request('user-1', '2026-10-13', 'full', null, 1.0, 'approver-1', null);
            });
    }

    public function test_request_after_migrated_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('submitted')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->request('user-1', '2026-10-13', 'full', null, 1.0, 'approver-1', null);
            });
    }

    // ---- 提出(share) ----

    public function test_share_records_shared_event_while_submitted(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->share('wf-1');
            })
            ->assertRecorded([new PaidLeaveRequestLifecycleShared(workflowRequestId: 'wf-1')]);
    }

    public function test_share_is_rejected_after_approved(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->approved('approver-1')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->share('wf-1');
            });
    }

    public function test_share_is_rejected_when_not_requested(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->share('wf-1');
            });
    }

    // ---- 承認(approve) ----

    public function test_approve_records_approved_event_while_submitted(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->approve('approver-1');
            })
            ->assertRecorded([new PaidLeaveRequestLifecycleApproved(approvedByUserId: 'approver-1', userId: 'user-1')]);
    }

    public function test_approve_sets_status_to_approved(): void
    {
        $this->assertSame('approved', $this->statusAfter([$this->requested(), $this->approved('approver-1')]));
    }

    public function test_second_approve_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->approved('approver-1')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->approve('approver-1');
            });
    }

    public function test_approve_is_rejected_after_cancelled(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->cancelled()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->approve('approver-1');
            });
    }

    public function test_approve_is_rejected_when_returned(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->returned()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->approve('approver-1');
            });
    }

    // ---- 差戻し(return) ----

    public function test_return_records_returned_event_while_submitted(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->return('approver-1', '日程を確認してください');
            })
            ->assertRecorded([
                new PaidLeaveRequestLifecycleReturned(
                    returnedByUserId: 'approver-1',
                    comment: '日程を確認してください',
                    userId: 'user-1',
                ),
            ]);
    }

    public function test_return_sets_status_to_returned(): void
    {
        $this->assertSame('returned', $this->statusAfter([$this->requested(), $this->returned()]));
    }

    public function test_return_is_rejected_after_approved(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->approved('approver-1')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->return('approver-1', null);
            });
    }

    public function test_return_is_rejected_when_already_returned(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->returned()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->return('approver-1', null);
            });
    }

    // ---- 再提出(resubmit) ----

    public function test_resubmit_records_resubmitted_event_while_returned(): void
    {
        // 再提出は申請の内容(対象日・取得単位など)をそのまま持つ(残数側が新しい消化記録を作るため)。
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->returned()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->resubmit('user-1');
            })
            ->assertRecorded([
                new PaidLeaveRequestLifecycleResubmitted(
                    resubmittedByUserId: 'user-1',
                    userId: 'user-1',
                    targetDate: '2026-10-12',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: 'approver-1',
                    reason: '私用',
                    requestGroupId: null,
                    workflowRequestId: null,
                ),
            ]);
    }

    public function test_resubmit_sets_status_to_submitted(): void
    {
        $this->assertSame(
            'submitted',
            $this->statusAfter([$this->requested(), $this->returned(), $this->resubmitted()]),
        );
    }

    public function test_resubmit_is_rejected_when_submitted(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->resubmit('user-1');
            });
    }

    public function test_resubmit_is_rejected_after_cancelled(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->returned(), $this->cancelled()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->resubmit('user-1');
            });
    }

    public function test_resubmit_is_rejected_after_approved(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->approved('approver-1')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->resubmit('user-1');
            });
    }

    // ---- 取消(cancel) ----

    public function test_cancel_records_cancelled_event_while_submitted(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('user-1', '予定変更');
            })
            ->assertRecorded([
                new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: 'user-1', reason: '予定変更'),
            ]);
    }

    public function test_cancel_records_cancelled_event_while_returned(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->returned()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('user-1', null);
            })
            ->assertRecorded([new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: 'user-1', reason: null, userId: 'user-1')]);
    }

    public function test_cancel_records_cancelled_event_while_approved(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->approved('approver-1')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('admin-1', '管理者による取消');
            })
            ->assertRecorded([
                new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: 'admin-1', reason: '管理者による取消'),
            ]);
    }

    public function test_cancel_sets_status_to_cancelled(): void
    {
        $this->assertSame('cancelled', $this->statusAfter([$this->requested(), $this->cancelled()]));
    }

    public function test_cancel_is_rejected_when_not_requested(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('user-1', null);
            });
    }

    public function test_second_cancel_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested(), $this->cancelled()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('user-1', null);
            });
    }

    // ---- 引き継ぎ(migrate) ----

    public function test_migrate_records_migrated_event_from_none(): void
    {
        $event = $this->migrated('approved', hasUsage: true);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->migrate(
                    userId: 'user-1',
                    targetDate: '2026-08-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: 'approver-1',
                    reason: null,
                    requestGroupId: null,
                    workflowRequestId: 'wf-1',
                    status: 'approved',
                    usageId: 'usage-1',
                    hasUsage: true,
                );
            })
            ->assertRecorded([$event]);
    }

    public function test_migrate_sets_status_to_each_migrated_status(): void
    {
        $this->assertSame('submitted', $this->statusAfter([$this->migrated('submitted')]));
        $this->assertSame('returned', $this->statusAfter([$this->migrated('returned')]));
        $this->assertSame('approved', $this->statusAfter([$this->migrated('approved')]));
        $this->assertSame('cancelled', $this->statusAfter([$this->migrated('cancelled')]));
    }

    public function test_second_migrate_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('submitted')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->migrate(
                    userId: 'user-1',
                    targetDate: '2026-08-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: 'approver-1',
                    reason: null,
                    requestGroupId: null,
                    workflowRequestId: 'wf-1',
                    status: 'submitted',
                    usageId: null,
                    hasUsage: false,
                );
            });
    }

    public function test_migrate_is_rejected_after_request(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->requested()])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->migrate(
                    userId: 'user-1',
                    targetDate: '2026-08-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: 'approver-1',
                    reason: null,
                    requestGroupId: null,
                    workflowRequestId: 'wf-1',
                    status: 'submitted',
                    usageId: null,
                    hasUsage: false,
                );
            });
    }

    public function test_migrate_rejects_status_that_cannot_be_migrated(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->migrate(
                    userId: 'user-1',
                    targetDate: '2026-08-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: 'approver-1',
                    reason: null,
                    requestGroupId: null,
                    workflowRequestId: 'wf-1',
                    status: 'none',
                    usageId: null,
                    hasUsage: false,
                );
            });
    }

    public function test_migrated_submitted_can_be_approved(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('submitted')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->approve('approver-1');
            })
            ->assertRecorded([new PaidLeaveRequestLifecycleApproved(approvedByUserId: 'approver-1', userId: 'user-1')]);
    }

    public function test_migrated_returned_can_be_resubmitted_but_not_approved(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('returned')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->resubmit('user-1');
            })
            ->assertRecorded([
                new PaidLeaveRequestLifecycleResubmitted(
                    resubmittedByUserId: 'user-1',
                    userId: 'user-1',
                    targetDate: '2026-08-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: 'approver-1',
                    reason: null,
                    requestGroupId: null,
                    workflowRequestId: 'wf-1',
                ),
            ]);

        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('returned')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->approve('approver-1');
            });
    }

    public function test_migrated_cancelled_cannot_be_cancelled_again(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('cancelled')])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('user-1', null);
            });
    }

    public function test_migrated_approved_with_usage_can_be_cancelled(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('approved', hasUsage: true)])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('user-1', null);
            })
            ->assertRecorded([new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: 'user-1', reason: null, userId: 'user-1')]);
    }

    public function test_migrated_approved_without_usage_cannot_be_cancelled(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('移行前の申請のため取消できません。付与日数の調整で対応してください。');

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('approved', hasUsage: false)])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('user-1', null);
            });
    }

    public function test_migrated_submitted_without_usage_can_still_be_cancelled(): void
    {
        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given([$this->migrated('submitted', hasUsage: false)])
            ->when(function (PaidLeaveRequestAggregate $aggregate) {
                $aggregate->cancel('user-1', null);
            })
            ->assertRecorded([new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: 'user-1', reason: null, userId: 'user-1')]);
    }

    public function test_migrated_approved_without_usage_is_approved(): void
    {
        $this->assertTrue($this->stateAfter([$this->migrated('approved', hasUsage: false)])['approved']);
    }

    // ---- 問い合わせメソッド ----

    public function test_query_methods_reflect_each_status(): void
    {
        $this->assertSame(
            ['submitted' => false, 'returned' => false, 'approved' => false, 'cancelled' => false],
            $this->stateAfter([]),
        );

        $this->assertSame(
            ['submitted' => true, 'returned' => false, 'approved' => false, 'cancelled' => false],
            $this->stateAfter([$this->requested()]),
        );

        $this->assertSame(
            ['submitted' => false, 'returned' => true, 'approved' => false, 'cancelled' => false],
            $this->stateAfter([$this->requested(), $this->returned()]),
        );

        $this->assertSame(
            ['submitted' => false, 'returned' => false, 'approved' => true, 'cancelled' => false],
            $this->stateAfter([$this->requested(), $this->approved('approver-1')]),
        );

        $this->assertSame(
            ['submitted' => false, 'returned' => false, 'approved' => false, 'cancelled' => true],
            $this->stateAfter([$this->requested(), $this->cancelled()]),
        );
    }

    // ---- ヘルパー ----

    /**
     * 与えたイベントを適用した後の状態を取得する(ステータス文字列)。
     *
     * @param  object[]  $events
     */
    private function statusAfter(array $events): string
    {
        $status = null;

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given($events)
            ->when(function (PaidLeaveRequestAggregate $aggregate) use (&$status) {
                $status = $aggregate->status();
            });

        return $status;
    }

    /**
     * 与えたイベントを適用した後の問い合わせメソッドの結果を取得する。
     *
     * @param  object[]  $events
     * @return array<string, bool>
     */
    private function stateAfter(array $events): array
    {
        $state = [];

        PaidLeaveRequestAggregate::fake(self::REQUEST_ID)
            ->given($events)
            ->when(function (PaidLeaveRequestAggregate $aggregate) use (&$state) {
                $state = [
                    'submitted' => $aggregate->isSubmitted(),
                    'returned' => $aggregate->isReturned(),
                    'approved' => $aggregate->isApproved(),
                    'cancelled' => $aggregate->isCancelled(),
                ];
            });

        return $state;
    }

    private function requested(): PaidLeaveRequestLifecycleRequested
    {
        return new PaidLeaveRequestLifecycleRequested(
            userId: 'user-1',
            targetDate: '2026-10-12',
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: 'approver-1',
            reason: '私用',
            requestGroupId: null,
            workflowRequestId: null,
        );
    }

    private function approved(string $approverUserId): PaidLeaveRequestLifecycleApproved
    {
        return new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approverUserId);
    }

    private function returned(): PaidLeaveRequestLifecycleReturned
    {
        return new PaidLeaveRequestLifecycleReturned(returnedByUserId: 'approver-1', comment: '差戻し');
    }

    private function resubmitted(): PaidLeaveRequestLifecycleResubmitted
    {
        return new PaidLeaveRequestLifecycleResubmitted(resubmittedByUserId: 'user-1');
    }

    private function cancelled(): PaidLeaveRequestLifecycleCancelled
    {
        return new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: 'user-1', reason: null);
    }

    private function migrated(string $status, bool $hasUsage = true): PaidLeaveRequestLifecycleMigrated
    {
        return new PaidLeaveRequestLifecycleMigrated(
            userId: 'user-1',
            targetDate: '2026-08-10',
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: 'approver-1',
            reason: null,
            requestGroupId: null,
            workflowRequestId: 'wf-1',
            status: $status,
            usageId: $hasUsage ? 'usage-1' : null,
            hasUsage: $hasUsage,
        );
    }
}
