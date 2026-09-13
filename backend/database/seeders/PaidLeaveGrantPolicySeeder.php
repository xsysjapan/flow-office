<?php

namespace Database\Seeders;

use App\Models\PaidLeaveGrantExpiryPolicy;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveProportionalGrantPolicy;
use Illuminate\Database\Seeder;

/**
 * 通常付与表・比例付与表・時効ポリシーの法定値(v1)をシードする
 * (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md 論点4・論点16、
 * 労働基準法第39条第1項・第2項・第3項、労働基準法施行規則第24条の3・別表第1)。
 *
 * 値は本変更セット実装時点で一般的に参照される法定基準表に基づく。運用開始前に
 * 社労士確認を行うこと(CLAUDE.md原則8「最終設定は社労士確認が前提」)。
 */
class PaidLeaveGrantPolicySeeder extends Seeder
{
    public const VERSION = 'v1';

    public function run(): void
    {
        $this->seedNormalGrantPolicy();
        $this->seedProportionalGrantPolicy();
        $this->seedExpiryPolicy();
    }

    /**
     * 通常付与(労働基準法第39条第1項・第2項)。継続勤務月数→付与日数。
     */
    private function seedNormalGrantPolicy(): void
    {
        $rows = [
            6 => 10,
            18 => 11,
            30 => 12,
            42 => 14,
            54 => 16,
            66 => 18,
            78 => 20,
        ];

        foreach ($rows as $months => $days) {
            PaidLeaveGrantPolicy::query()->updateOrCreate(
                ['version' => self::VERSION, 'continuous_service_months' => $months],
                ['grant_days' => $days, 'is_active' => true],
            );
        }
    }

    /**
     * 比例付与(労働基準法第39条第3項、労働基準法施行規則第24条の3・別表第1)。
     * 週所定労働日数区分×継続勤務月数→付与日数。
     */
    private function seedProportionalGrantPolicy(): void
    {
        $table = [
            // 週4日(年間所定労働日数169日〜216日)
            '4' => [6 => 7, 18 => 8, 30 => 9, 42 => 10, 54 => 12, 66 => 13, 78 => 15],
            // 週3日(年間所定労働日数121日〜168日)
            '3' => [6 => 5, 18 => 6, 30 => 6, 42 => 8, 54 => 9, 66 => 10, 78 => 11],
            // 週2日(年間所定労働日数73日〜120日)
            '2' => [6 => 3, 18 => 4, 30 => 4, 42 => 5, 54 => 6, 66 => 6, 78 => 7],
            // 週1日(年間所定労働日数48日〜72日)
            '1' => [6 => 1, 18 => 2, 30 => 2, 42 => 2, 54 => 3, 66 => 3, 78 => 3],
        ];

        foreach ($table as $category => $rows) {
            foreach ($rows as $months => $days) {
                PaidLeaveProportionalGrantPolicy::query()->updateOrCreate(
                    [
                        'version' => self::VERSION,
                        'weekly_scheduled_days_category' => $category,
                        'continuous_service_months' => $months,
                    ],
                    ['grant_days' => $days, 'is_active' => true],
                );
            }
        }
    }

    /**
     * 時効(労働基準法第115条、既定2年)。
     */
    private function seedExpiryPolicy(): void
    {
        PaidLeaveGrantExpiryPolicy::query()->updateOrCreate(
            ['version' => self::VERSION],
            ['expiry_years' => 2, 'is_active' => true],
        );
    }
}
