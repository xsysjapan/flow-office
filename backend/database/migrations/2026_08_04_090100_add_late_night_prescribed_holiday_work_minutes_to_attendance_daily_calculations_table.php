<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 深夜所定休日労働時間(late_night_prescribed_holiday_work_minutes)を追加する。
 * 既存のlate_night_legal_holiday_work_minutes(深夜法定休日労働時間)と対称の項目で、
 * 所定休日労働のうち深夜時間帯にかかった分を保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->integer('late_night_prescribed_holiday_work_minutes')->default(0)->after('late_night_legal_holiday_work_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->dropColumn('late_night_prescribed_holiday_work_minutes');
        });
    }
};
