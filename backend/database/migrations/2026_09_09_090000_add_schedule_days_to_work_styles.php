<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/changesets/20260906-paid-leave-schedule-assessment/spec.md 論点3・4。
 * 通常/比例/シフト判定(App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier)に
 * 必要な「所定」労働日数の契約情報。実績(EmployeeCalendarEntry)とは別概念のため、
 * 管理者が直接入力するnullable列として追加する。未入力時はNeedsReview判定とする。
 */
return new class extends Migration
{
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
        Schema::table('work_styles', fn (Blueprint $table) => $table->dropColumn([
            'weekly_scheduled_days',
            'annual_scheduled_days',
            'agreed_scheduled_days_per_year',
        ]));
    }
};
