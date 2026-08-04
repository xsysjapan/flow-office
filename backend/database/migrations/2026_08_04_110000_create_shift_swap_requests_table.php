<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 振替休日申請(special_leave_requestsと同じ形)。承認とバックオフィス処理は別ステータス系列
 * で管理する方針と同様、振替休日申請も独立したステータス系列として持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_swap_requests', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする。行の新規作成自体もShiftSwapRequestProjector
            // 経由で行えるようにするため(docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->date('target_date');
            $table->date('substitute_date');
            $table->foreignUuid('approver_user_id')->nullable()->constrained('users');
            $table->string('status')->default('submitted'); // submitted, approved, returned, cancelled
            $table->text('reason')->nullable();
            $table->text('return_comment')->nullable();
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
        Schema::dropIfExists('shift_swap_requests');
    }
};
