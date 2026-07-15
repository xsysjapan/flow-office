<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->renameColumn('actual_work_minutes', 'work_minutes');
            $table->renameColumn('non_statutory_overtime_minutes', 'statutory_within_overtime_minutes');
            $table->renameColumn('statutory_overtime_minutes', 'statutory_excess_overtime_minutes');
            $table->renameColumn('late_night_minutes', 'late_night_work_minutes');
            $table->renameColumn('regular_work_late_night_minutes', 'late_night_prescribed_work_minutes');
            $table->renameColumn('non_statutory_overtime_late_night_minutes', 'late_night_statutory_within_overtime_minutes');
            $table->renameColumn('statutory_overtime_late_night_minutes', 'late_night_statutory_excess_overtime_minutes');
            $table->renameColumn('company_holiday_work_minutes', 'prescribed_holiday_work_minutes');
            $table->renameColumn('legal_holiday_late_night_minutes', 'late_night_legal_holiday_work_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->renameColumn('work_minutes', 'actual_work_minutes');
            $table->renameColumn('statutory_within_overtime_minutes', 'non_statutory_overtime_minutes');
            $table->renameColumn('statutory_excess_overtime_minutes', 'statutory_overtime_minutes');
            $table->renameColumn('late_night_work_minutes', 'late_night_minutes');
            $table->renameColumn('late_night_prescribed_work_minutes', 'regular_work_late_night_minutes');
            $table->renameColumn('late_night_statutory_within_overtime_minutes', 'non_statutory_overtime_late_night_minutes');
            $table->renameColumn('late_night_statutory_excess_overtime_minutes', 'statutory_overtime_late_night_minutes');
            $table->renameColumn('prescribed_holiday_work_minutes', 'company_holiday_work_minutes');
            $table->renameColumn('late_night_legal_holiday_work_minutes', 'legal_holiday_late_night_minutes');
        });
    }
};