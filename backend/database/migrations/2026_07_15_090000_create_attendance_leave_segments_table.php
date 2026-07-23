<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1日のうち勤務予定を勤務しなかった時間帯を、どの制度で処理したかの入力
 * (docs/07-usecases-attendance.md「不就労時間の処理区分」参照)。
 *
 * 全休・半休・時間単位の有給休暇は既存の paid_leave_requests/paid_leave_usages と
 * attendance_days.work_type で管理する(このテーブルの対象外)。このテーブルは、それ以外の
 * 理由で勤務しなかった時間帯(欠勤・特別休暇等)を、日次実績とは独立に時間帯単位で保持する。
 * 日次編集(EditAttendanceDay/CreateAttendanceDay)のたびに全件入れ替える(attendance_breaksと
 * 同じ扱い)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_leave_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('attendance_day_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // absence(欠勤) / special_leave(その他特別休暇)
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leave_segments');
    }
};
