<?php

namespace App\Domain\BackOffice\Support;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\BackOfficeTaskStatus;

/**
 * バックオフィスタスクの担当者設定の判定(DBに触れない純粋な判定)。
 * 完了・取消のタスクは担当者を変更できず、現在と同じ担当者の指定は何もしない(冪等)。
 */
class BackOfficeTaskAssignmentDecision
{
    public const NOOP = 'noop';

    public const ASSIGN = 'assign';

    /**
     * @throws DomainRuleException 完了・取消済みのタスクの場合(同じ担当者の指定でも)
     */
    public function decide(?string $currentAssigneeId, string $status, string $requestedAssigneeId): string
    {
        if (in_array($status, [BackOfficeTaskStatus::COMPLETED, BackOfficeTaskStatus::CANCELLED], true)) {
            throw new DomainRuleException('完了・取消済みのタスクは担当者を変更できません。');
        }

        if ($currentAssigneeId === $requestedAssigneeId) {
            return self::NOOP;
        }

        return self::ASSIGN;
    }

    /**
     * 未着手のタスクへの担当者設定で、遷移マスタが許可していれば確認中へ進めるかどうか。
     */
    public function shouldMoveToInReview(string $status, bool $transitionAllowed): bool
    {
        return $status === BackOfficeTaskStatus::NOT_STARTED && $transitionAllowed;
    }
}
