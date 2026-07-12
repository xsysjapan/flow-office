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
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            // 裁量労働制(work_time_system=discretionary)のみなし労働時間。対象外の日はnull。
            $table->unsignedSmallInteger('deemed_work_minutes')->nullable()->after('actual_work_minutes');
            // 給与計算上使用する労働時間。通常はactual_work_minutesと同じだが、裁量労働制は
            // deemed_work_minutes を採用する(実際の勤務状況とは別に保持する。
            // docs/07-usecases-attendance.md「裁量労働制・管理監督者」参照)。
            $table->unsignedSmallInteger('payroll_work_minutes')->default(0)->after('deemed_work_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->dropColumn(['deemed_work_minutes', 'payroll_work_minutes']);
        });
    }
};
