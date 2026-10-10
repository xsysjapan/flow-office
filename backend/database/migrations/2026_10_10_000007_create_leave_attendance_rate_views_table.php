<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 有給(PaidLeaveSchedule文脈)の出勤率の入力ビュー(仕様確定事項F・論点9)。
 *
 * - leave_attendance_rate_days: 利用者×日付の出勤率ビュー。AttendanceRateAssessorだけが読む。
 * - leave_attendance_rate_leaves: 休暇申請3種の行(申請ID単位。有給は申請IDごとに系統を1つだけ使う)。
 * - leave_attendance_rate_attendance_days: 勤怠日ID→利用者・日付・退勤済みの対応。
 *
 * いずれも勤怠・休暇申請・カレンダーのイベントから LeaveAttendanceRateProjector が作る派生データ(再生成可能)。
 * 他文脈のテーブルは読まない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_attendance_rate_days', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id');
            $table->date('work_date');
            $table->boolean('is_working_day')->default(false); // 分母: employee_calendar_entry.assigned の isWorkingDay
            $table->boolean('attended')->default(false); // 退勤済みの勤怠日がある
            $table->json('full_leave_kinds')->nullable(); // 全休(午前・午後の半休の組合せを含む)の休暇の種類
            $table->json('partial_leave_kinds')->nullable(); // 半休・時間休の休暇の種類

            $table->unique(['user_id', 'work_date']);
        });

        Schema::create('leave_attendance_rate_leaves', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('leave_kind'); // paid, special, compensatory
            $table->uuid('leave_request_id');
            $table->uuid('user_id');
            $table->date('work_date');
            $table->string('unit'); // full, am_half, pm_half, hourly
            $table->uuid('usage_id')->nullable(); // 有給(cutover後の系統)の消化記録ID
            $table->uuid('workflow_request_id')->nullable();
            $table->string('request_status'); // submitted, approved, returned, cancelled
            $table->string('source'); // legacy_paid, paid_account, paid_request, special, compensatory

            $table->unique(['leave_kind', 'leave_request_id']);
            $table->index(['user_id', 'work_date']);
            $table->index('usage_id');
        });

        Schema::create('leave_attendance_rate_attendance_days', function (Blueprint $table) {
            $table->uuid('id')->primary(); // 勤怠日ID
            $table->uuid('user_id');
            $table->date('work_date');
            $table->boolean('clocked_out');

            $table->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_attendance_rate_attendance_days');
        Schema::dropIfExists('leave_attendance_rate_leaves');
        Schema::dropIfExists('leave_attendance_rate_days');
    }
};
