<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-C009 手順4: 既存年度を複製して翌年度を作成する。
 */
class DuplicateCompanyCalendarYear implements Command
{
    public function __construct(
        public readonly string $sourceCompanyCalendarYearId,
        public readonly string $createdByUserId,
    ) {}
}
