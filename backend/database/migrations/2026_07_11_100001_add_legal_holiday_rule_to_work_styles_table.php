<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            // 法定休日の与え方: weekly(毎週1日) / four_weeks_four_days(4週4日以上の変形休日制)。
            // シフト制(is_shift_based)の勤務形態にのみ意味を持つ(docs/08-usecases-calendar-shift.md UC-C005)。
            $table->string('legal_holiday_rule')->default('weekly')->after('is_shift_based');
            // four_weeks_four_days採用時の4週間の起算日。就業規則で定める前提のためマスタ化する。
            $table->date('four_week_period_start_date')->nullable()->after('legal_holiday_rule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn(['legal_holiday_rule', 'four_week_period_start_date']);
        });
    }
};
