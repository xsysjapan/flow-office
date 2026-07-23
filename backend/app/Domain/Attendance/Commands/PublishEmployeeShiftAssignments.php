<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 3交代制シフト表を公開する(UC-C004 手順6)。対象社員(部署一括または個別指定)・
 * 対象月の下書き中(is_published=false)のシフトパターン割当をまとめて公開する。
 */
class PublishEmployeeShiftAssignments implements Command
{
    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $yearMonth,
        public readonly string $publishedByUserId,
    ) {}
}
