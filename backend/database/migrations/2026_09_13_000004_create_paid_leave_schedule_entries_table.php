<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`のProjection。
     * 主キーはCommandHandler側で発番されるscheduleEntryId(UUID)であり、行の新規作成
     * (PaidLeaveScheduleEntryCreated)自体もProjector経由で行う
     * (.claude/skills/add-projection「集約ルートのUUID化」参照)。
     */
    public function up(): void
    {
        Schema::create('paid_leave_schedule_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->date('scheduled_on');
            $table->string('category');
            $table->decimal('candidate_grant_days', 4, 1);
            $table->string('status');
            $table->uuid('latest_assessment_id')->nullable();
            $table->boolean('is_manually_overridden')->default(false);
            $table->string('manual_override_reason')->nullable();
            $table->foreignUuid('manual_override_by_user_id')->nullable()->constrained('users');
            $table->timestamp('manual_override_at')->nullable();
            $table->uuid('grant_id')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['scheduled_on', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_schedule_entries');
    }
};
