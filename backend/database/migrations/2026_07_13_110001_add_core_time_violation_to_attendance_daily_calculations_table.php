<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * フレックスタイム制(work_time_system=flex)のコアタイム違反。労働時間の不足とは別枠の
 * 警告として扱う(指示書 7.4節)。フレックス以外の勤務形態では常にfalse。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->boolean('core_time_violation')->default(false)->after('legal_holiday_late_night_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->dropColumn('core_time_violation');
        });
    }
};
