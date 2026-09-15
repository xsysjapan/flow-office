<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 全社共通(work_style_id=null)の一斉付与ルールを既定値として1件シードする
 * (docs/changesets/20260913-paid-leave-fiscal-grant-and-nav/spec.md 移植)。
 * 既にアクティブな全社ルールが存在する場合はスキップし、冪等性を保つ
 * (このマイグレーションの複数回実行・既存環境での重複作成防止)。
 */
return new class extends Migration
{
    public function up(): void
    {
        $alreadyExists = DB::table('paid_leave_grant_rules')
            ->whereNull('work_style_id')
            ->where('is_active', true)
            ->exists();

        if ($alreadyExists) {
            return;
        }

        DB::table('paid_leave_grant_rules')->insert([
            'name' => '全社共通(4月一斉付与)',
            'work_style_id' => null,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_type' => 'mass_grant_month',
            'mass_grant_month' => 4,
            'grant_cycle_months' => 12,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // シードデータの削除は行わない(既存の付与実績・Scheduleエントリが
        // このルールを参照している可能性があるため)。
    }
};
