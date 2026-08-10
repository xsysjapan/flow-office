<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * work_calendars/work_calendar_days/employee_shift_assignments を
     * company_calendars/company_calendar_days/employee_calendar_entries へ
     * リネームする(スキーマ・データ形状は変更しない)。work_styles.calendar_id も
     * work_styles.company_calendar_id へ、attendance_days.shift_assignment_id も
     * attendance_days.calendar_entry_id へリネームする。
     */
    public function up(): void
    {
        Schema::rename('work_calendars', 'company_calendars');
        Schema::rename('work_calendar_days', 'company_calendar_days');
        Schema::rename('employee_shift_assignments', 'employee_calendar_entries');

        Schema::table('work_styles', function (Blueprint $table) {
            $table->renameColumn('calendar_id', 'company_calendar_id');
        });

        Schema::table('attendance_days', function (Blueprint $table) {
            $table->renameColumn('shift_assignment_id', 'calendar_entry_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->renameColumn('calendar_entry_id', 'shift_assignment_id');
        });

        Schema::table('work_styles', function (Blueprint $table) {
            $table->renameColumn('company_calendar_id', 'calendar_id');
        });

        Schema::rename('employee_calendar_entries', 'employee_shift_assignments');
        Schema::rename('company_calendar_days', 'work_calendar_days');
        Schema::rename('company_calendars', 'work_calendars');
    }
};
