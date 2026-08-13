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
     * 祝日属性(is_public_holiday/public_holiday_name)と勤務区分(schedule_state)を
     * 旧day_type/is_working_day/is_company_holidayから分離する(UC-C010)。旧カラムは
     * 2段階廃止のため削除しない。既存行はis_working_dayからschedule_stateを導出して
     * バックフィルする。
     */
    public function up(): void
    {
        Schema::table('company_calendar_days', function (Blueprint $table) {
            $table->boolean('is_public_holiday')->default(false)->after('date');
            $table->string('public_holiday_name')->nullable()->after('is_public_holiday');
            $table->string('schedule_state')->default('WORK')->after('public_holiday_name'); // WORK, OFF
        });

        DB::table('company_calendar_days')->where('is_working_day', true)->update(['schedule_state' => 'WORK']);
        DB::table('company_calendar_days')->where('is_working_day', false)->update(['schedule_state' => 'OFF']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_calendar_days', function (Blueprint $table) {
            $table->dropColumn(['is_public_holiday', 'public_holiday_name', 'schedule_state']);
        });
    }
};
