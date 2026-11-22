<?php

namespace App\Domain\BackOffice\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * バックオフィスタスクの担当者が設定された(初回・変更とも)。ステータスは持たない。
 * 未着手タスクへの割当による確認中への変化は backoffice_task.status_changed で別に記録する。
 */
class BackOfficeTaskAssigned extends ShouldBeStored
{
    public function __construct(
        public readonly string $assignedUserId,
        public readonly string $assignedByUserId,
        public readonly ?string $previousAssignedUserId = null,
    ) {}
}
