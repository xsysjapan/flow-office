<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 通常/比例/シフト勤務の法定区分判定(`GrantCategoryClassifier`)に必要な、
     * 契約上の所定労働日数をWorkStyleへ追加する(spec.md 論点3)。実績日数
     * (`EmployeeCalendarEntry`)とは別概念であり、管理者が契約条件として直接入力する。
     * 未入力の場合はNeedsReview判定となる(対象外: 既存社員への一括データ投入)。
     */
    public function up(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->unsignedTinyInteger('weekly_scheduled_days')->nullable()->after('prescribed_weekly_minutes');
            $table->unsignedSmallInteger('annual_scheduled_days')->nullable()->after('weekly_scheduled_days');
            $table->unsignedSmallInteger('agreed_scheduled_days_per_year')->nullable()->after('annual_scheduled_days');
        });
    }

    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn(['weekly_scheduled_days', 'annual_scheduled_days', 'agreed_scheduled_days_per_year']);
        });
    }
};
