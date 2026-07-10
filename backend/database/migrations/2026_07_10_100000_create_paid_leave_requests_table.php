<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-P003/UC-P004: 有給申請・承認。承認とバックオフィス処理は別ステータス系列で管理する
 * (docs/03-architecture.md)方針と同様、有給申請も独立したステータス系列として持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('approver_user_id')->constrained('users');
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
        Schema::dropIfExists('paid_leave_requests');
    }
};
