<?php

namespace App\Domain\Export\Services\ExpenseCsv;

use App\Models\BackOfficeTask;
use App\Models\ExpenseClaim;

/**
 * 既定フォーマット(後方互換)。UC-X012の従来出力(タスク単位・カンマ区切り・UTF-8)を維持する。
 */
class GenericExpenseCsvFormat implements ExpenseCsvFormat
{
    public function header(): array
    {
        return ['task_id', 'title', 'employee_name', 'amount', 'status', 'created_at'];
    }

    public function rows(BackOfficeTask $task, ExpenseClaim $claim): array
    {
        return [[
            $task->id,
            $task->title,
            $claim->employee?->name,
            $claim->total_amount,
            $task->status,
            $task->created_at->toDateString(),
        ]];
    }

    public function delimiter(): string
    {
        return ',';
    }

    public function encoding(): string
    {
        return 'UTF-8';
    }

    public function fileExtension(): string
    {
        return 'csv';
    }
}
