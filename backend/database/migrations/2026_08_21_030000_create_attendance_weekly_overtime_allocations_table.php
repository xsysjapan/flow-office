<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_weekly_overtime_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('attendance_day_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('week_start_date');
            $table->unsignedSmallInteger('prescribed_minutes')->default(0);
            $table->unsignedSmallInteger('non_prescribed_minutes')->default(0);
            $table->unsignedSmallInteger('late_night_prescribed_minutes')->default(0);
            $table->unsignedSmallInteger('late_night_non_prescribed_minutes')->default(0);
            $table->foreignUuid('allocated_by_user_id')->constrained('users');
            $table->timestamps();
            $table->index('week_start_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_weekly_overtime_allocations');
    }
};
