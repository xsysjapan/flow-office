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
        Schema::table('company_calendars', function (Blueprint $table) {
            // DBレベルのデフォルトは設定しない(sqlite/mysqlでのJSON既定値の扱いが煩雑なため)。
            // 未設定(null)時のフォールバックはCompanyCalendar::effectiveWeekdayHolidayPattern()で
            // アプリケーション側に解決する。
            $table->json('weekday_holiday_pattern')->nullable()->after('holiday_calendar_source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_calendars', function (Blueprint $table) {
            $table->dropColumn('weekday_holiday_pattern');
        });
    }
};
