<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 代休の消化(special_leave_usagesと同じ形)。失効日が近い付与分(nullは最後)から
 * 優先的に消し込むため、1回の承認で複数grantにまたがって消化される場合がある。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensatory_leave_usages', function (Blueprint $table) {
            $table->id();
            // stored_events.id。CompensatoryLeaveUsageProjectorの冪等性をこのユニーク制約で
            // 担保する。この行自体はcompensatory_leave_grant集約が記録するcompensatory_leave.used
            // イベントの派生データであり、自身は集約ルートではないためDB採番のままでよい。
            $table->unsignedBigInteger('stored_event_id')->nullable()->unique();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('attendance_day_id')->constrained();
            $table->foreignUuid('compensatory_leave_grant_id')->constrained();
            $table->foreignUuid('compensatory_leave_request_id')->constrained();
            $table->date('used_on');
            $table->decimal('used_days', 4, 1)->default(0);
            $table->unsignedInteger('used_minutes')->nullable();
            $table->string('usage_type'); // full, am_half, pm_half, hourly
            $table->timestamps();

            $table->index(['user_id', 'used_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensatory_leave_usages');
    }
};
