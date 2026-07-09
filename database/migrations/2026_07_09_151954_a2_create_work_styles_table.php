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
        Schema::create('work_styles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('work_time_system')->default('fixed'); // fixed, shortened, shift_based 等
            $table->unsignedSmallInteger('prescribed_daily_minutes');
            $table->unsignedSmallInteger('prescribed_weekly_minutes');
            $table->time('default_start_time')->nullable();
            $table->time('default_end_time')->nullable();
            $table->unsignedSmallInteger('default_break_minutes')->default(60);
            $table->foreignId('calendar_id')->constrained('work_calendars');
            $table->boolean('is_shift_based')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_styles');
    }
};
