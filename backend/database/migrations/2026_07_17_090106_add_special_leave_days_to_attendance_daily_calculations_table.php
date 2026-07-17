<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇(special_leave_requests/attendance_days.work_type)の全休・半休分を
 * paid_leave_daysと同じ形で日次集計に持たせる。既存のspecial_leave_minutes列は、
 * 廃止した欠勤区分由来の集計(0固定)から、この特別休暇消化由来の集計
 * (時間単位特別休暇の消化分)に意味を変える。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->decimal('special_leave_days', 4, 2)->default(0)->after('paid_leave_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->dropColumn('special_leave_days');
        });
    }
};
