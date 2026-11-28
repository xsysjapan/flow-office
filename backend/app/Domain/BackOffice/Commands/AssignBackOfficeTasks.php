<?php

namespace App\Domain\BackOffice\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * バックオフィスタスクの担当者の一括変更(全部か無しか)。1件でも変更できないタスクがあれば
 * 何も記録せず BulkOperationRejected になる。
 */
class AssignBackOfficeTasks implements Command
{
    /**
     * @param  list<string>  $backOfficeTaskIds
     */
    public function __construct(
        public readonly array $backOfficeTaskIds,
        public readonly string $assignedUserId,
        public readonly string $assignedByUserId,
    ) {}
}
