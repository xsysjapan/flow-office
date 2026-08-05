<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 代休の消化(使用)申請(special_leave_requestsと同じ形)。付与(Grant)は勤怠実績から
 * 自動導出されるため申請不要だが、消化は特別休暇と同じ申請制フローを持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensatory_leave_requests', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする(SpecialLeaveRequestと同じ理由)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('approver_user_id')->constrained('users');
            $table->string('status')->default('submitted'); // submitted, approved, returned, cancelled
            $table->string('leave_type'); // full, am_half, pm_half, hourly
            $table->date('target_date');
            $table->decimal('hours', 4, 2)->nullable(); // leave_type=hourlyのときのみ使用
            $table->decimal('requested_days', 4, 1)->default(0);
            $table->unsignedInteger('requested_minutes')->nullable();
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
        Schema::dropIfExists('compensatory_leave_requests');
    }
};
