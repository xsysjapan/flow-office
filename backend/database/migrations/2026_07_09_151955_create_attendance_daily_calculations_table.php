<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Projection: attendance.day_calculated イベントから再生成可能。
        Schema::create('attendance_daily_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('attendance_day_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('planned_work_minutes')->default(0);
            $table->unsignedSmallInteger('actual_work_minutes')->default(0);
            $table->unsignedSmallInteger('prescribed_work_minutes')->default(0);
            $table->unsignedSmallInteger('non_statutory_overtime_minutes')->default(0);
            $table->unsignedSmallInteger('statutory_overtime_minutes')->default(0);
            $table->unsignedSmallInteger('late_night_minutes')->default(0);
            $table->unsignedSmallInteger('legal_holiday_work_minutes')->default(0);
            $table->unsignedSmallInteger('company_holiday_work_minutes')->default(0);
            $table->unsignedSmallInteger('legal_holiday_late_night_minutes')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_calculations');
    }
};
