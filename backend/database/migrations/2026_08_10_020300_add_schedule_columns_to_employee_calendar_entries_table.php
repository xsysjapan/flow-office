<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * employee_calendar_entriesにschedule_state/entry_type/source_type/bulk_operation_id/
     * revisionを追加する(docs/16-database-schema.md、UC-C013)。旧day_type/is_working_day/
     * is_company_holidayは2段階廃止のため削除しない。既存行はis_working_dayから
     * schedule_stateを導出してバックフィルする。
     */
    public function up(): void
    {
        Schema::table('employee_calendar_entries', function (Blueprint $table) {
            $table->string('schedule_state')->default('UNASSIGNED')->after('is_company_holiday'); // UNASSIGNED, WORK, OFF, LEAVE
            $table->string('entry_type')->nullable()->after('schedule_state'); // OVERRIDE, SHIFT_ASSIGNMENT, HOLIDAY_WORK, SUBSTITUTE_HOLIDAY, MANUAL_ADJUSTMENT
            $table->string('source_type')->nullable()->after('entry_type'); // calendar_generated, rotation_generated, bulk_operation, manual
            $table->uuid('bulk_operation_id')->nullable()->after('source_type');
            $table->unsignedInteger('revision')->default(1)->after('bulk_operation_id');
        });

        DB::table('employee_calendar_entries')->where('is_working_day', true)->update(['schedule_state' => 'WORK']);
        DB::table('employee_calendar_entries')->where('is_working_day', false)->update(['schedule_state' => 'OFF']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_calendar_entries', function (Blueprint $table) {
            $table->dropColumn(['schedule_state', 'entry_type', 'source_type', 'bulk_operation_id', 'revision']);
        });
    }
};
