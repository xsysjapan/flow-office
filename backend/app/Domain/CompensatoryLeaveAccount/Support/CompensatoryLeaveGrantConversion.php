<?php

namespace App\Domain\CompensatoryLeaveAccount\Support;

/**
 * 休日出勤の実労働時間から代休付与量(日数/分)を換算する副作用のない純粋クラス。
 * Eloquent・SystemSettingへは一切アクセスせず、設定値は引数で受け取る。
 *
 * 移植元: App\Domain\CompensatoryLeave\Services\CompensatoryLeaveGrantCalculator::resolveGrantedAmount
 * - half_day: 実労働分 > 半日しきい値(nullは0扱い)なら1.0日、以下なら0.5日(分はnull)。
 * - hourly: 日数は0.0、分は実労働分そのもの。
 * - それ以外(daily・未設定・未知の値): 1.0日(分はnull)。
 */
class CompensatoryLeaveGrantConversion
{
    /**
     * @return array{0: float, 1: ?int}  [付与日数, 付与分]
     */
    public function resolve(?string $unit, ?int $halfDayThresholdMinutes, int $workMinutes): array
    {
        return match ($unit) {
            'half_day' => $workMinutes > ($halfDayThresholdMinutes ?? 0)
                ? [1.0, null]
                : [0.5, null],
            'hourly' => [0.0, $workMinutes],
            default => [1.0, null], // daily
        };
    }
}
