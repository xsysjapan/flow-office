<?php

namespace Database\Seeders;

use App\Models\PaidLeaveGrantExpiryPolicy;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveProportionalGrantPolicy;
use Illuminate\Database\Seeder;

/**
 * 通常付与表・比例付与表・時効ポリシーの法定値(労働基準法39条・115条)をversion=1として
 * 投入する。docs/changesets/20260906-paid-leave-schedule-assessment/spec.md 論点4。
 */
class PaidLeaveGrantPolicySeeder extends Seeder
{
    private const VERSION = 1;

    /**
     * 通常付与表: 継続勤務月数 → 付与日数(依頼書§34)。
     */
    private const REGULAR_TABLE = [
        6 => 10.0,
        18 => 11.0,
        30 => 12.0,
        42 => 14.0,
        54 => 16.0,
        66 => 18.0,
        78 => 20.0,
    ];

    /**
     * 比例付与表: 週所定労働日数 → [継続勤務月数 → 付与日数]。
     * 労働基準法39条3項の比例付与表(通常付与表と同じ継続勤務月数の区切りに対応)。
     */
    private const PROPORTIONAL_TABLE = [
        4 => [6 => 7.0, 18 => 8.0, 30 => 9.0, 42 => 10.0, 54 => 12.0, 66 => 13.0, 78 => 15.0],
        3 => [6 => 5.0, 18 => 6.0, 30 => 6.0, 42 => 8.0, 54 => 9.0, 66 => 10.0, 78 => 11.0],
        2 => [6 => 3.0, 18 => 4.0, 30 => 4.0, 42 => 5.0, 54 => 6.0, 66 => 6.0, 78 => 7.0],
        1 => [6 => 1.0, 18 => 2.0, 30 => 2.0, 42 => 2.0, 54 => 3.0, 66 => 3.0, 78 => 3.0],
    ];

    public function run(): void
    {
        foreach (self::REGULAR_TABLE as $months => $days) {
            PaidLeaveGrantPolicy::query()->updateOrCreate(
                ['version' => self::VERSION, 'continuous_service_months' => $months],
                ['grant_days' => $days],
            );
        }

        foreach (self::PROPORTIONAL_TABLE as $weeklyDays => $milestones) {
            foreach ($milestones as $months => $days) {
                PaidLeaveProportionalGrantPolicy::query()->updateOrCreate(
                    ['version' => self::VERSION, 'weekly_scheduled_days' => $weeklyDays, 'continuous_service_months' => $months],
                    ['grant_days' => $days],
                );
            }
        }

        PaidLeaveGrantExpiryPolicy::query()->updateOrCreate(
            ['version' => self::VERSION],
            ['expiry_years' => 2],
        );
    }
}
