<?php

namespace App\Models;

/**
 * attendance_days.day_classification: その勤務日が労働日/所定休日/法定休日のいずれかを表す
 * 簡略区分。`AttendanceCalculator`が`employee_calendar_entries.is_legal_holiday`(または
 * `LegalHolidayResolver`による推定)・`is_company_holiday`から判定し、日次計算のたびに
 * 保存し直す(派生データであり、`AttendanceDailyCalculationProjector`経由で再生成可能)。
 *
 * `employee_calendar_entries.day_type`(weekday/legal_holiday/company_holiday/
 * special_working_day)とは別に、attendance_days向けに3値へ簡略化したもの。
 */
final class DayClassification
{
    public const WORKING_DAY = 'working_day';

    public const PRESCRIBED_HOLIDAY = 'prescribed_holiday';

    public const LEGAL_HOLIDAY = 'legal_holiday';
}
