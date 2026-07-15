<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 法定外残業(`statutory_overtime_minutes`)のうち22:00〜05:00の深夜時間帯と重なる分。
 * `late_night_minutes`(深夜時間の総量)の内訳であり、二重計上ではなく既存の値の分解として扱う
 * (docs/07-usecases-attendance.md 参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->unsignedSmallInteger('statutory_overtime_late_night_minutes')->default(0)->after('late_night_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->dropColumn('statutory_overtime_late_night_minutes');
        });
    }
};
