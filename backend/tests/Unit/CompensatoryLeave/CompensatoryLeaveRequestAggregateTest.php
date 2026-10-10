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

    public function test_request_records_the_request_with_its_content(): void
    {
        $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))
            ->assertRecorded([
                new CompensatoryLeaveRequested(
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

    public function test_a_second_request_for_the_same_id_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        $aggregate = $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST));
        $this->requested($aggregate);
    }

    public function test_share_is_only_allowed_while_submitted(): void
    {
        $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))
            ->share('workflow-1')
            ->assertRecorded([new CompensatoryLeaveRequestShared(workflowRequestId: 'workflow-1')]);

        $this->expectException(DomainRuleException::class);

        $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))
            ->approve(self::APPROVER)
            ->share('workflow-1');
    }

    public function test_approve_is_only_allowed_while_submitted(): void
    {
        $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))
            ->approve(self::APPROVER)
            ->assertRecorded([new CompensatoryLeaveRequestApproved(approvedByUserId: self::APPROVER, userId: self::USER)]);

        $this->expectException(DomainRuleException::class);

        $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))
            ->approve(self::APPROVER)
            ->approve(self::APPROVER);
    }

    public function test_return_then_resubmit_returns_to_submitted_with_the_same_content(): void
    {
        $aggregate = $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))
            ->returnRequest(self::APPROVER, '差戻し')
            ->resubmit(self::USER);

        $aggregate->assertRecorded([
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

    public function test_resubmit_is_only_allowed_while_returned(): void
    {
        $this->expectException(DomainRuleException::class);

        $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))->resubmit(self::USER);
    }

    public function test_cancel_is_allowed_from_submitted_returned_and_approved_but_not_twice(): void
    {
        $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))
            ->approve(self::APPROVER)
            ->cancel(self::USER)
            ->assertRecorded([new CompensatoryLeaveRequestCancelled(cancelledByUserId: self::USER, userId: self::USER)]);

        $this->expectException(DomainRuleException::class);

        $this->requested(CompensatoryLeaveRequestAggregate::fake(self::REQUEST))
            ->cancel(self::USER)
            ->cancel(self::USER);
    }
}
