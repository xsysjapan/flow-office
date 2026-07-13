<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * フレックスタイム制(work_time_system=flex)の設定項目。指示書 7章参照。
 * 清算期間は初期実装では月単位のみとし(settlement_start_dayは起算日、既定1日)、
 * 総労働時間は既存の prescribed_daily_minutes(1日の標準労働時間) ×
 * 清算期間内の所定労働日数 から算出する(FlexSettlementSummaryCalculator)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->unsignedTinyInteger('settlement_start_day')->nullable()->after('variable_period_start_day');
            $table->boolean('core_time_enabled')->default(false)->after('settlement_start_day');
            $table->time('core_time_start')->nullable()->after('core_time_enabled');
            $table->time('core_time_end')->nullable()->after('core_time_start');
            $table->time('flexible_time_start')->nullable()->after('core_time_end');
            $table->time('flexible_time_end')->nullable()->after('flexible_time_start');
        });
    }

    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn([
                'settlement_start_day', 'core_time_enabled', 'core_time_start',
                'core_time_end', 'flexible_time_start', 'flexible_time_end',
            ]);
        });
    }
};
