<?php

namespace Tests\Unit\CompensatoryLeave;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestResubmitted;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Tests\TestCase;

/**
 * 代休申請の集約(申請状態だけを扱う)の状態遷移の単体テスト。Projection(Eloquent)は使わない。
 * 遷移: none → submitted → approved / returned / cancelled、returned → submitted(再提出) / cancelled、approved → cancelled。
 * 書き方は特別休暇のSpecialLeaveRequestAggregateTestと同じ(fake()->when()->assertRecorded())。
 */
class CompensatoryLeaveRequestAggregateTest extends TestCase
{
    private const REQUEST = 'request-1';

    private const USER = 'user-1';

    private const APPROVER = 'approver-1';

    private function requested(CompensatoryLeaveRequestAggregate $aggregate): CompensatoryLeaveRequestAggregate
    {
        return $aggregate->request(
            userId: self::USER,
            targetDate: '2026-10-10',
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            requestedMinutes: null,
            approverUserId: self::APPROVER,
            reason: '理由',
        );
    }

    private function requestedEvent(): CompensatoryLeaveRequested
    {
        return new CompensatoryLeaveRequested(
            userId: self::USER,
            targetDate: '2026-10-10',
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            requestedMinutes: null,
            approverUserId: self::APPROVER,
            reason: '理由',
        );
    }

    public function test_request_is_recorded_once(): void
    {
        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)
            ->when(fn (CompensatoryLeaveRequestAggregate $aggregate) => $this->requested($aggregate))
            ->assertRecorded([$this->requestedEvent()]);
    }

    public function test_second_request_with_the_same_id_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)->when(function (CompensatoryLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate);
            $this->requested($aggregate);
        });
    }

    public function test_share_is_rejected_before_the_request(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)->when(
            fn (CompensatoryLeaveRequestAggregate $aggregate) => $aggregate->share('wf-1'),
        );
    }

    public function test_share_records_the_workflow_request(): void
    {
        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)
            ->when(function (CompensatoryLeaveRequestAggregate $aggregate) {
                $this->requested($aggregate)->share('wf-1');
            })
            ->assertRecorded([
                $this->requestedEvent(),
                new CompensatoryLeaveRequestShared(workflowRequestId: 'wf-1'),
            ]);
    }

    public function test_approve_is_rejected_when_not_submitted(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)->when(function (CompensatoryLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate)->returnRequest(self::APPROVER, '差戻し');
            $aggregate->approve(self::APPROVER);
        });
    }

    public function test_approve_records_the_approval_with_the_applicant(): void
    {
        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)
            ->when(function (CompensatoryLeaveRequestAggregate $aggregate) {
                $this->requested($aggregate)->approve(self::APPROVER);
            })
            ->assertRecorded([
                $this->requestedEvent(),
                new CompensatoryLeaveRequestApproved(approvedByUserId: self::APPROVER, userId: self::USER),
            ]);
    }

    public function test_return_then_resubmit_records_the_same_content(): void
    {
        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)
            ->when(function (CompensatoryLeaveRequestAggregate $aggregate) {
                $this->requested($aggregate)->returnRequest(self::APPROVER, '差戻し')->resubmit(self::USER);
            })
            ->assertRecorded([
                $this->requestedEvent(),
                new CompensatoryLeaveRequestReturned(returnedByUserId: self::APPROVER, comment: '差戻し', userId: self::USER),
                new CompensatoryLeaveRequestResubmitted(
                    resubmittedByUserId: self::USER,
                    userId: self::USER,
                    targetDate: '2026-10-10',
                    leaveType: 'full',
                    hours: null,
                    requestedDays: 1.0,
                    requestedMinutes: null,
                    approverUserId: self::APPROVER,
                    reason: '理由',
                ),
            ]);
    }

    public function test_resubmit_is_rejected_when_not_returned(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)->when(
            fn (CompensatoryLeaveRequestAggregate $aggregate) => $this->requested($aggregate)->resubmit(self::USER),
        );
    }

    public function test_cancel_is_recorded_once_and_cannot_be_repeated(): void
    {
        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)
            ->when(function (CompensatoryLeaveRequestAggregate $aggregate) {
                $this->requested($aggregate)->approve(self::APPROVER)->cancel(self::USER);
            })
            ->assertRecorded([
                $this->requestedEvent(),
                new CompensatoryLeaveRequestApproved(approvedByUserId: self::APPROVER, userId: self::USER),
                new CompensatoryLeaveRequestCancelled(cancelledByUserId: self::USER, userId: self::USER),
            ]);

        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveRequestAggregate::fake(self::REQUEST)->when(function (CompensatoryLeaveRequestAggregate $aggregate) {
            $this->requested($aggregate)->cancel(self::USER);
            $aggregate->cancel(self::USER);
        });
    }
}
