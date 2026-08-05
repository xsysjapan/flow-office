<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 代休の付与(compensatory_leave_grants)。special_leave_grantsと異なり、申請ではなく
 * 休日出勤の勤怠実績(attendance_days)から自動導出される(App\Domain\CompensatoryLeave参照)。
 * attendance_day_idをユニークキーとしてupsertするため、1つの休日出勤実績につき1行のみ存在する。
 * status='draft'の間は月次確認画面からの参照のみで残数チェックの対象にせず、月次提出時に
 * 'confirmed'へ確定して初めて消化申請の対象になる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensatory_leave_grants', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする(SpecialLeaveGrantと同じ理由)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('attendance_day_id')->unique()->constrained();
            $table->date('work_date');
            $table->decimal('granted_days', 4, 1)->default(0);
            $table->unsignedInteger('granted_minutes')->nullable();
            $table->decimal('used_days', 4, 1)->default(0);
            $table->unsignedInteger('used_minutes')->nullable();
            $table->decimal('remaining_days', 4, 1)->default(0);
            $table->unsignedInteger('remaining_minutes')->nullable();
            $table->string('status')->default('draft'); // draft, confirmed, cancelled
            $table->timestamp('confirmed_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_on'], 'compensatory_leave_grants_user_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensatory_leave_grants');
    }
};
