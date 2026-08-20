<?php

namespace App\Domain\Attendance\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;

class WeeklyOvertimeAllocationGuard
{
    public function __construct(private readonly WeeklyOvertimeCalculator $calculator) {}

    public function ensureAllocated(string $userId, string $yearMonth): void
    {
        $unallocated = collect($this->calculator->calculateForMonth($userId, $yearMonth))
            ->filter(fn (array $week) => $week['unallocated_weekly_statutory_excess_overtime_minutes'] > 0)
            ->values();

        if ($unallocated->isEmpty()) {
            return;
        }

        $details = $unallocated->map(fn (array $week) => sprintf(
            '%s〜%s: %d分',
            $week['week_start_date'],
            $week['week_end_date'],
            $week['unallocated_weekly_statutory_excess_overtime_minutes'],
        ))->implode('、');

        throw new DomainRuleException("週40時間超の法定外労働時間が未振分です（{$details}）。対象の勤務日へ振り分けてください。");
    }
}
