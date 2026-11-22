<?php

namespace Tests\Unit\BackOffice;

use App\Domain\BackOffice\Support\BackOfficeTaskAssignmentDecision;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\BackOfficeTaskStatus;
use PHPUnit\Framework\TestCase;

/**
 * バックオフィスタスクの担当者設定の業務ルール(論点3・論点2)。
 */
class BackOfficeTaskAssignmentDecisionTest extends TestCase
{
    public function test_assigns_an_unassigned_task(): void
    {
        $decision = new BackOfficeTaskAssignmentDecision;

        $this->assertSame(
            BackOfficeTaskAssignmentDecision::ASSIGN,
            $decision->decide(null, BackOfficeTaskStatus::NOT_STARTED, 'user-a'),
        );
    }

    public function test_reassigns_an_assigned_task_to_another_user(): void
    {
        $decision = new BackOfficeTaskAssignmentDecision;

        $this->assertSame(
            BackOfficeTaskAssignmentDecision::ASSIGN,
            $decision->decide('user-a', BackOfficeTaskStatus::PROCESSING, 'user-b'),
        );
    }

    public function test_does_nothing_when_the_same_user_is_requested(): void
    {
        $decision = new BackOfficeTaskAssignmentDecision;

        $this->assertSame(
            BackOfficeTaskAssignmentDecision::NOOP,
            $decision->decide('user-a', BackOfficeTaskStatus::IN_REVIEW, 'user-a'),
        );
    }

    public function test_rejects_completed_and_cancelled_tasks(): void
    {
        $decision = new BackOfficeTaskAssignmentDecision;

        foreach ([BackOfficeTaskStatus::COMPLETED, BackOfficeTaskStatus::CANCELLED] as $status) {
            try {
                $decision->decide(null, $status, 'user-a');
                $this->fail("{$status} のタスクは担当者を設定できないはず");
            } catch (DomainRuleException $e) {
                $this->assertSame('完了・取消済みのタスクは担当者を変更できません。', $e->getMessage());
            }
        }
    }

    public function test_rejects_completed_task_even_when_the_same_user_is_requested(): void
    {
        $decision = new BackOfficeTaskAssignmentDecision;

        $this->expectException(DomainRuleException::class);

        $decision->decide('user-a', BackOfficeTaskStatus::COMPLETED, 'user-a');
    }

    public function test_moves_a_not_started_task_to_in_review_when_the_transition_is_allowed(): void
    {
        $decision = new BackOfficeTaskAssignmentDecision;

        $this->assertTrue($decision->shouldMoveToInReview(BackOfficeTaskStatus::NOT_STARTED, true));
    }

    public function test_keeps_a_not_started_task_when_the_transition_is_not_allowed(): void
    {
        $decision = new BackOfficeTaskAssignmentDecision;

        $this->assertFalse($decision->shouldMoveToInReview(BackOfficeTaskStatus::NOT_STARTED, false));
    }

    public function test_keeps_a_processing_task_even_when_the_transition_is_allowed(): void
    {
        $decision = new BackOfficeTaskAssignmentDecision;

        $this->assertFalse($decision->shouldMoveToInReview(BackOfficeTaskStatus::PROCESSING, true));
    }
}
