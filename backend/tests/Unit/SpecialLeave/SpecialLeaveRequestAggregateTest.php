<?php

namespace Tests\Unit\SpecialLeave;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestResubmitted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use Tests\TestCase;

/**
 * 特別休暇申請の集約(申請状態だけを扱う)の状態遷移の単体テスト。Projection(Eloquent)は使わない。
 * 遷移: submitted → approved / returned / cancelled、returned → submitted(再提出) / cancelled、approved → cancelled。
 */
class SpecialLeaveRequestAggregateTest extends TestCase
{
    private const REQUEST = 'request-1';
    private const USER = 'user-1';
    private const APPROVER = 'approver-1';
    private const TYPE = 1;

    private function requested(SpecialLeaveRequestAggregate $aggregate): SpecialLeaveRequestAggregate
    {
        return $aggregate->request(
            userId: self::USER,
            specialLeaveTypeId: self::TYPE,
            targetDate: '2026-10-10',
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: self::APPROVER,
            reason: '理由',
        );
    }

    private function requestedEvent(): SpecialLeaveRequested
    {
        return new SpecialLeaveRequested(
            userId: self::USER,
            specialLeaveTypeId: self::TYPE,
            targetDate: '2026-10-10',
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: self::APPROVER,
            reason: '理由',
            requestGroupId: null,
        );
    }

    public function test_request_is_recorded_once(): void
    {
        SpecialLeaveRequestAggregate::fake(self::REQUEST)
            ->when(fn (SpecialLeaveRequestAggregate $aggregate) => $this->requested($aggregate))
            ->assertRecorded([$this->requestedEvent()]);
    }

    public function test_second_request_with_the_same_id_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(function (SpecialLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate);
            $this->requested($aggregate);
        });
    }

    public function test_share_is_rejected_before_the_request(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(
            fn (SpecialLeaveRequestAggregate $aggregate) => $aggregate->share('wf-1'),
        );
    }

    public function test_share_records_the_workflow_request(): void
    {
        SpecialLeaveRequestAggregate::fake(self::REQUEST)
            ->when(function (SpecialLeaveRequestAggregate $aggregate) {
                $this->requested($aggregate)->share('wf-1');
            })
            ->assertRecorded([
                $this->requestedEvent(),
                new SpecialLeaveRequestShared(workflowRequestId: 'wf-1'),
            ]);
    }

    public function test_approve_records_the_content_and_whether_a_grant_is_required(): void
    {
        SpecialLeaveRequestAggregate::fake(self::REQUEST)
            ->when(function (SpecialLeaveRequestAggregate $aggregate) {
                $this->requested($aggregate)->approve(self::APPROVER, requiresGrant: true);
            })
            ->assertRecorded([
                $this->requestedEvent(),
                new SpecialLeaveRequestApproved(
                    approvedByUserId: self::APPROVER,
                    userId: self::USER,
                    specialLeaveTypeId: self::TYPE,
                    targetDate: '2026-10-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    requiresGrant: true,
                ),
            ]);
    }

    public function test_approve_is_rejected_when_not_submitted(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(function (SpecialLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate)->returnRequest(self::APPROVER, '差戻し');
            $aggregate->approve(self::APPROVER, requiresGrant: true);
        });
    }

    public function test_return_then_resubmit_records_the_same_content(): void
    {
        SpecialLeaveRequestAggregate::fake(self::REQUEST)
            ->when(function (SpecialLeaveRequestAggregate $aggregate) {
                $this->requested($aggregate)->returnRequest(self::APPROVER, '日程を確認')->resubmit(self::USER);
            })
            ->assertRecorded([
                $this->requestedEvent(),
                new SpecialLeaveRequestReturned(
                    returnedByUserId: self::APPROVER,
                    comment: '日程を確認',
                    userId: self::USER,
                    specialLeaveTypeId: self::TYPE,
                    targetDate: '2026-10-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                ),
                new SpecialLeaveRequestResubmitted(
                    resubmittedByUserId: self::USER,
                    userId: self::USER,
                    specialLeaveTypeId: self::TYPE,
                    targetDate: '2026-10-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    approverUserId: self::APPROVER,
                    reason: '理由',
                    requestGroupId: null,
                ),
            ]);
    }

    public function test_resubmit_is_rejected_when_not_returned(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(
            fn (SpecialLeaveRequestAggregate $aggregate) => $this->requested($aggregate)->resubmit(self::USER),
        );
    }

    public function test_return_is_rejected_when_already_returned(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(function (SpecialLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate)->returnRequest(self::APPROVER, '1回目')->returnRequest(self::APPROVER, '2回目');
        });
    }

    public function test_cancel_is_allowed_from_submitted_returned_and_approved(): void
    {
        SpecialLeaveRequestAggregate::fake(self::REQUEST)
            ->when(function (SpecialLeaveRequestAggregate $aggregate) {
                $this->requested($aggregate)->cancel(self::USER);
            })
            ->assertRecorded([
                $this->requestedEvent(),
                new SpecialLeaveRequestCancelled(
                    cancelledByUserId: self::USER,
                    userId: self::USER,
                    specialLeaveTypeId: self::TYPE,
                    targetDate: '2026-10-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    reason: null,
                ),
            ]);

        // 差戻し中・承認済みからも取り消せる(状態の確認のみ。例外が出ないこと)。
        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(function (SpecialLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate)->returnRequest(self::APPROVER, '差戻し')->cancel(self::USER);
        });
        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(function (SpecialLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate)->approve(self::APPROVER, requiresGrant: false)->cancel(self::USER);
        });
        $this->assertTrue(true);
    }

    public function test_cancel_is_rejected_twice(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(function (SpecialLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate)->cancel(self::USER)->cancel(self::USER);
        });
    }

    public function test_approve_after_cancel_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveRequestAggregate::fake(self::REQUEST)->when(function (SpecialLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate)->cancel(self::USER)->approve(self::APPROVER, requiresGrant: true);
        });
    }
}
