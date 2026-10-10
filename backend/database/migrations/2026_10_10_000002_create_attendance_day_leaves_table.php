<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤怠の休暇ビュー(attendance_day_leaves)と、その投影専用の補助表(attendance_day_leave_paid_usages)。
 *
 * 休暇申請文脈のイベントから AttendanceDayLeaveProjector が作る派生データ(再生成可能)。
 * 差戻し・取消では行を削除せず request_status を returned/cancelled にする(系統の切り替え判定に行を使う)。
 * 有効な休暇(submitted/approved)だけを読む問い合わせは App\Domain\Attendance\Support\AttendanceDayLeaves が担う。
 *
 * attendance_day_leave_paid_usages は、cutover後の有給の確定・取消イベント(usageIdしか持たない)を
 * 申請IDへ対応付けるための、このProjector専用の表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_day_leaves', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('leave_kind'); // paid, special, compensatory
            $table->uuid('leave_request_id');
            $table->uuid('user_id');
            $table->date('work_date');
            $table->string('unit'); // full, am_half, pm_half, hourly
            $table->decimal('hours', 5, 2)->nullable();
            $table->integer('minutes')->nullable();
            $table->integer('special_leave_type_id')->nullable();
            $table->uuid('workflow_request_id')->nullable();
            $table->string('request_status'); // submitted, approved, returned, cancelled
            $table->string('source'); // legacy_paid, paid_account, paid_request, special, compensatory
            $table->timestamps();

            $table->unique(['leave_kind', 'leave_request_id']);
            $table->index(['user_id', 'work_date']);
        });

        Schema::create('attendance_day_leave_paid_usages', function (Blueprint $table) {
            // 有給の消化記録ID(PaidLeaveUsageDesignatedのusageId)→有給申請ID。
            $table->uuid('usage_id')->primary();
            $table->uuid('leave_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_day_leave_paid_usages');
        Schema::dropIfExists('attendance_day_leaves');
    }
};
