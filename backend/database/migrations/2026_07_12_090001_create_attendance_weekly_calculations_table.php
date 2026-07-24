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
        Schema::create('attendance_weekly_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('week_start_date');
            $table->date('week_end_date');
            $table->unsignedInteger('actual_work_minutes')->default(0);
            $table->unsignedInteger('daily_statutory_overtime_minutes')->default(0);
            $table->unsignedInteger('weekly_statutory_overtime_minutes')->default(0);
            $table->unsignedInteger('legal_holiday_work_minutes')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'week_start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_weekly_calculations');
    }
};
