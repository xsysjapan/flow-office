<?php

namespace App\Domain\BackOffice\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 自分が担当するバックオフィスタスクの一括完了(全部か無しか)。完了済みのタスクは飛ばす(イベントを記録しない)。
 * 1件でも完了できないタスクがあれば何も記録せず BulkOperationRejected になる。
 */
class CompleteBackOfficeTasks implements Command
{
    /**
     * @param  list<string>  $backOfficeTaskIds
     */
    public function __construct(
        public readonly array $backOfficeTaskIds,
        public readonly string $completedByUserId,
        public readonly ?string $comment = null,
    ) {}
}
