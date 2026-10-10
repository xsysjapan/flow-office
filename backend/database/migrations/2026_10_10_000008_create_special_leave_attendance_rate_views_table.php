<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇(SpecialLeave文脈)の出勤率の入力ビュー(仕様確定事項F・論点9)。
 *
 * PaidLeaveScheduleの leave_attendance_rate_* と同じ入力・同じ構造を、特別休暇の文脈が自分の表として持つ
 * (原則15: 他文脈のテーブルを読まないため。GrantScheduledSpecialLeaveHandlerはこの表だけを読む)。
 * 勤怠・休暇申請・カレンダーのイベントから SpecialLeaveAttendanceRateProjector が作る派生データ(再生成可能)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_leave_attendance_rate_days', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id');
            $table->date('work_date');
            $table->boolean('is_working_day')->default(false);
            $table->boolean('attended')->default(false);
            $table->uuid('work_style_id')->nullable(); // 所定の勤務形態(特別休暇の自動付与の対象判定。カレンダーの割当イベント由来)
            $table->json('full_leave_kinds')->nullable();
            $table->json('partial_leave_kinds')->nullable();

            $table->unique(['user_id', 'work_date']);
        });

        Schema::create('special_leave_attendance_rate_leaves', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('leave_kind');
            $table->uuid('leave_request_id');
            $table->uuid('user_id');
            $table->date('work_date');
            $table->string('unit');
            $table->uuid('usage_id')->nullable();
            $table->uuid('workflow_request_id')->nullable();
            $table->string('request_status');
            $table->string('source');

            $table->unique(['leave_kind', 'leave_request_id']);
            $table->index(['user_id', 'work_date']);
            $table->index('usage_id');
        });

        Schema::create('special_leave_attendance_rate_attendance_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->date('work_date');
            $table->boolean('clocked_out');

            $table->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_leave_attendance_rate_attendance_days');
        Schema::dropIfExists('special_leave_attendance_rate_leaves');
        Schema::dropIfExists('special_leave_attendance_rate_days');
    }
};
