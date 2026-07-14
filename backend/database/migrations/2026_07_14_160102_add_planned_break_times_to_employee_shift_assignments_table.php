<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤務予定の休憩を「合計分数」(planned_break_minutes)だけでなく開始・終了時刻でも持ち、
 * 日次勤怠の初期表示(打刻が無い日はスケジュールの休憩を含めて反映する)に使えるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_shift_assignments', function (Blueprint $table) {
            $table->dateTime('planned_break_start_at')->nullable()->after('planned_break_minutes');
            $table->dateTime('planned_break_end_at')->nullable()->after('planned_break_start_at');
        });
    }

    public function down(): void
    {
        Schema::table('employee_shift_assignments', function (Blueprint $table) {
            $table->dropColumn(['planned_break_start_at', 'planned_break_end_at']);
        });
    }
};
