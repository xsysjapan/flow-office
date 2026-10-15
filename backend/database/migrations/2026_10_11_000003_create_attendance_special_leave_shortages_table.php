<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤怠側の特別休暇の残数不足のビュー(attendance_special_leave_shortages)。
 *
 * 特別休暇口座(SpecialLeaveAccount)の消化記録のイベント(usage_designated・usage_confirmed・usage_cancelled)から、
 * 勤怠側のAttendanceSpecialLeaveShortageProjectorが作る派生データ(再生成可能)。月次APIの特別休暇の残数不足警告が、
 * 特別休暇の消化記録テーブル(special_leave_usages)を直接読まずにこのビューを読むために置く(原則15)。
 * 主キーは消化記録ID。unallocated_daysが0より大きい行が「承認済みだが残数不足で充当できなかった」休暇。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_special_leave_shortages', function (Blueprint $table) {
            $table->uuid('usage_id')->primary();
            $table->uuid('user_id');
            $table->date('work_date');
            $table->uuid('special_leave_request_id');
            $table->unsignedBigInteger('special_leave_type_id');
            $table->decimal('used_days', 4, 1)->default(0);
            $table->unsignedInteger('used_minutes')->nullable();
            $table->decimal('unallocated_days', 4, 1)->default(0);

            $table->index(['user_id', 'work_date'], 'att_sl_shortages_user_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_special_leave_shortages');
    }
};
