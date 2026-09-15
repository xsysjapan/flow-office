<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schedule Entry 1件に対して複数回記録されうる出勤率Assessmentの版履歴Projection
     * (spec.md 論点2)。「誰が・いつ・何を根拠に・どう判定し・誰が上書きしたか」を
     * 監査可能な形で残す(依頼書§30・§40)。
     */
    public function up(): void
    {
        Schema::create('paid_leave_schedule_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_entry_id')->constrained('paid_leave_schedule_entries');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('denominator_days');
            $table->unsignedInteger('attendance_days');
            $table->unsignedInteger('excluded_days')->default(0);
            $table->decimal('attendance_rate', 5, 2)->nullable();
            $table->string('policy_version');
            $table->string('automatic_result');
            $table->string('final_result');
            $table->string('override_reason')->nullable();
            $table->foreignUuid('overridden_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['schedule_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_schedule_assessments');
    }
};
