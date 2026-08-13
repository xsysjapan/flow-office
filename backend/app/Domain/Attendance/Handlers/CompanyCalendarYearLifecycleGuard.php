<?php

namespace App\Domain\Attendance\Handlers;

use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\CompanyCalendarYear;

/**
 * UC-C009 手順5: カレンダー年度の下書きへの差し戻し・廃止に共通するガード。
 * 対象年度(starts_on〜ends_on)に締め済み月(attendance_months承認済み以降)が
 * 1件でもある場合はどちらも行えない。
 */
class CompanyCalendarYearLifecycleGuard
{
    public static function hasClosedMonthsWithin(CompanyCalendarYear $companyCalendarYear): bool
    {
        $yearMonths = [];
        $cursor = $companyCalendarYear->starts_on->copy()->startOfMonth();
        $end = $companyCalendarYear->ends_on->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $yearMonths[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return AttendanceMonth::query()
            ->whereIn('year_month', $yearMonths)
            ->whereIn('status', [AttendanceMonthStatus::APPROVED, AttendanceMonthStatus::CLOSED])
            ->exists();
    }
}
