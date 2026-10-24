<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Models\PaidLeaveFiscalYearSetting;

/**
 * 有給の一斉付与日に使う年度開始月日を返す。付与予定の文脈の読み取りビュー
 * (`paid_leave_fiscal_year_settings`)を読む。行が無ければ4月1日。
 */
class FiscalYearStartResolver
{
    public const DEFAULT_MONTH = 4;

    public const DEFAULT_DAY = 1;

    /**
     * @return array{month: int, day: int}
     */
    public function resolve(): array
    {
        $setting = PaidLeaveFiscalYearSetting::query()->find(PaidLeaveFiscalYearSetting::SINGLETON_ID);

        if ($setting === null) {
            return ['month' => self::DEFAULT_MONTH, 'day' => self::DEFAULT_DAY];
        }

        return ['month' => (int) $setting->fiscal_year_start_month, 'day' => (int) $setting->fiscal_year_start_day];
    }
}
