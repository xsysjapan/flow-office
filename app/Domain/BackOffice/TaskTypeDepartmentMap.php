<?php

namespace App\Domain\BackOffice;

/**
 * backoffice_tasks.task_type ごとの初期担当部署 (docs/11-usecases-backoffice.md)。
 * 経費精算は経理担当者、名刺・備品・住所変更等は総務担当者が扱う。
 */
final class TaskTypeDepartmentMap
{
    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        return [
            'expense_reimbursement' => '経理部',
            'business_card' => '総務部',
            'supply_request' => '総務部',
            'general_affairs' => '総務部',
        ];
    }

    public static function departmentFor(string $taskType): ?string
    {
        return self::map()[$taskType] ?? null;
    }
}
