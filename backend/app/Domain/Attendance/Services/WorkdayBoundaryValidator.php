<?php

namespace App\Domain\Attendance\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

class WorkdayBoundaryValidator
{
    public function assertWithinBoundary(?WorkStyle $workStyle, string $workDate, ?Carbon $start, ?Carbon $end): void
    {
        if ($workStyle === null || $start === null || $end === null
            || $workStyle->workday_boundary_type === WorkStyle::WORKDAY_BOUNDARY_WORK_DATE) {
            return;
        }

        $boundaryTime = $workStyle->workday_boundary_type === WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT
            ? '00:00:00'
            : ($workStyle->workday_boundary_time ?? '00:00:00');
        $windowStart = Carbon::parse($workDate)->setTimeFromTimeString($boundaryTime);
        $windowEnd = $windowStart->copy()->addDay();

        if ($start->lt($windowStart) || $end->gt($windowEnd)) {
            throw new DomainRuleException(sprintf(
                '勤務実績は作業日の区切り（%s〜翌%s）の範囲内で入力してください。境界を跨ぐ勤務は日別に分けてください。',
                $windowStart->format('H:i'),
                $windowStart->format('H:i'),
            ));
        }
    }
}
