<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇の消化(paid_leave_usagesと同じ形)。失効日が近い付与分(nullは最後)から
 * 優先的に消し込むため、1回の承認で複数grantにまたがって消化される場合がある。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_leave_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('attendance_day_id')->constrained();
            $table->foreignId('special_leave_grant_id')->constrained();
            $table->foreignId('special_leave_request_id')->constrained();
            $table->date('used_on');
            $table->decimal('used_days', 4, 1);
            $table->unsignedSmallInteger('used_minutes')->nullable();
            $table->string('usage_type'); // full, am_half, pm_half, hourly
            $table->timestamps();

            $table->index(['user_id', 'used_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_leave_usages');
    }
};
