<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇申請(paid_leave_requestsと同じ形)。承認とバックオフィス処理は別ステータス系列
 * で管理する方針と同様、特別休暇申請も独立したステータス系列として持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_leave_requests', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする。行の新規作成自体もSpecialLeaveRequestProjector
            // 経由で行えるようにするため(docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignId('special_leave_type_id')->constrained();
            $table->foreignUuid('approver_user_id')->constrained('users');
            $table->string('status')->default('submitted'); // submitted, approved, returned, cancelled
            $table->string('leave_type'); // full, am_half, pm_half, hourly
            $table->date('target_date');
            $table->decimal('hours', 4, 2)->nullable(); // leave_type=hourlyのときのみ使用
            $table->decimal('requested_days', 4, 1);
            $table->text('reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['approver_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_leave_requests');
    }
};
