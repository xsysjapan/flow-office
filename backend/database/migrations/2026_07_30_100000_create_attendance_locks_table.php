<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤怠ロック (UC-A008/UC-A010)。月次勤怠の提出時に対象期間の日次勤怠を編集不可にし、
 * 差戻し時に解除する。`scope_type`で月・週・日いずれの単位のロックかを表せるようにしておく
 * (現時点で発行するのは`month`のみ)。特定のドメインイベントの主キー(attendance_months.id等)
 * には紐付けず、user_id + 期間(period_start_date〜period_end_date)で対象日を判定する
 * (将来、週・日単位のロックを別イベントから同じテーブルに追記できるようにするため)。
 *
 * `workflow_request_id`は将来、勤怠の修正申請ワークフロー経由でロックが発行される場合に
 * 紐付けるための任意項目(現時点のAttendanceMonth提出フローでは使わずnull)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_locks', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type'); // month / week / day
            $table->date('period_start_date');
            $table->date('period_end_date');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignUuid('workflow_request_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'period_start_date', 'period_end_date', 'unlocked_at'], 'attendance_locks_user_period_unlocked_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_locks');
    }
};
