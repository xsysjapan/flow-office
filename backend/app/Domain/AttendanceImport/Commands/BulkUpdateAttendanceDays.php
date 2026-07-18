<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 月次一括更新(docs/26-usecases-monthly-import.md「一括更新API」)。楽観ロック
 * (expectedVersion)・冪等性(idempotencyKey)に対応する。
 */
class BulkUpdateAttendanceDays implements Command
{
    /**
     * @param  array<int, array{date: string, startTime: ?string, endTime: ?string, breaks: array<int, array{startTime: string, endTime: string}>, workLocationType: ?string, source: string}>  $days
     */
    public function __construct(
        public readonly int $draftId,
        public readonly int $expectedVersion,
        public readonly array $days,
        public readonly int $updatedByUserId,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
