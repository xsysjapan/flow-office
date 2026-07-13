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
        Schema::table('system_settings', function (Blueprint $table) {
            // 前月分の勤怠を提出すべき当月の日(UC-N001「勤怠未提出」警告の基準)。
            $table->unsignedTinyInteger('attendance_submission_deadline_day')->default(5)->after('default_timezone');
            // 前月分の勤怠を締めるべき当月の日(UC-N001「月次締め前警告」の基準)。
            $table->unsignedTinyInteger('attendance_month_close_deadline_day')->default(10)->after('attendance_submission_deadline_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['attendance_submission_deadline_day', 'attendance_month_close_deadline_day']);
        });
    }
};
