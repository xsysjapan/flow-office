<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 週次勤怠は日次勤怠の編集ビューであり、月のように独立した集計単位として扱わない
 * (CLAUDE.md「週次勤怠は日次勤怠の編集ビュー」)。週40時間の判定は
 * App\Domain\Attendance\Services\WeeklyOvertimeCalculator が画面表示のたびに
 * 日次実績から都度計算する参考情報とし、Projectionとして永続化しない。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('attendance_weekly_calculations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
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
};
