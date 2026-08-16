<?php

namespace App\Domain\CompensatoryLeave\Services;

use App\Models\SystemSetting;

/**
 * 休日出勤の実労働時間から代休付与日数を算出する共通ロジック
 * (docs/09-usecases-paid-leave.md: 取得単位はsystem_settings.compensatory_leave_unit等で
 * マスタ化する。ルートCLAUDE.md「法務判断が必要な値はマスタ化する」)。
 * SyncCompensatoryLeaveGrantHandler(勤怠実績からの自動導出)・GrantCompensatoryLeaveHandler
 * (管理者による手動付与)の両方から同一ロジックとして呼び出す。
 */
class CompensatoryLeaveGrantCalculator
{
    /**
     * @return array{0: float, 1: ?int}
     */
    public static function resolveGrantedAmount(SystemSetting $settings, int $workMinutes): array
    {
        return match ($settings->compensatory_leave_unit) {
            'half_day' => $workMinutes > ($settings->compensatory_leave_half_day_threshold_minutes ?? 0)
                ? [1.0, null]
                : [0.5, null],
            'hourly' => [0.0, $workMinutes],
            default => [1.0, null], // daily
        };
    }
}
