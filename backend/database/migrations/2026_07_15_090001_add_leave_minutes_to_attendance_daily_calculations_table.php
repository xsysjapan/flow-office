<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 欠勤・有給・特別休暇を、日次集計(1テーブル)の中で横断的に把握できるようにする列。
 * (docs/07-usecases-attendance.md「不就労時間の処理区分」参照)
 *
 * - absence_minutes / special_leave_minutes: attendance_leave_segmentsから算出する。
 * - paid_leave_days: attendance_days.work_type(paid_leave_full/am_half/pm_half)から算出する
 *   (全休=1.0、半休=0.5)。時間単位有給はここに含めず、paid_leave_minutesで表す。
 * - paid_leave_minutes: 対象日のpaid_leave_usages(usage_type=hourly)のused_minutes合計。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->unsignedSmallInteger('absence_minutes')->default(0)->after('core_time_violation');
            $table->unsignedSmallInteger('special_leave_minutes')->default(0)->after('absence_minutes');
            $table->decimal('paid_leave_days', 4, 2)->default(0)->after('special_leave_minutes');
            $table->unsignedSmallInteger('paid_leave_minutes')->default(0)->after('paid_leave_days');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->dropColumn(['absence_minutes', 'special_leave_minutes', 'paid_leave_days', 'paid_leave_minutes']);
        });
    }
};
