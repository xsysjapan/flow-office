<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * カレンダー年度の日別設定を、本体の現在の曜日休日パターンから再生成する(下書きの年度のみ)。
 */
class RegenerateCompanyCalendarYear implements Command
{
    public function __construct(
        public readonly string $companyCalendarYearId,
        public readonly string $updatedByUserId,
    ) {}
}
