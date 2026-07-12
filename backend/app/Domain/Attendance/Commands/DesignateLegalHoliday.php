<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 法定休日「決めない方式」(work_styles.legal_holiday_rule=undetermined)において、
 * 特定の週の法定休日を指定する。指定が無い週は自動推定(週内で休みとなっている最後の日)を
 * 使う(LegalHolidayResolver参照)。
 */
class DesignateLegalHoliday implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $weekStartDate,
        public readonly string $designatedDate,
        public readonly string $reason,
        public readonly int $designatedByUserId,
    ) {}
}
