<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 深夜時間帯(22:00〜05:00)の労働を、所定労働・法定内残業・法定外残業(既存の
 * statutory_overtime_late_night_minutes)の3区分に分解する(docs/07-usecases-attendance.md参照)。
 * 3区分の合計はlate_night_minutesに一致する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->unsignedSmallInteger('regular_work_late_night_minutes')->default(0)->after('late_night_minutes');
            $table->unsignedSmallInteger('non_statutory_overtime_late_night_minutes')->default(0)->after('regular_work_late_night_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->dropColumn(['regular_work_late_night_minutes', 'non_statutory_overtime_late_night_minutes']);
        });
    }
};
