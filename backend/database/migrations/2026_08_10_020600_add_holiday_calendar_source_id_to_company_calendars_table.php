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
            $table->foreignUuid('holiday_calendar_source_id')->nullable()->after('fiscal_year_start_day')
                ->constrained('holiday_calendar_sources')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_calendars', function (Blueprint $table) {
            $table->dropForeign(['holiday_calendar_source_id']);
            $table->dropColumn('holiday_calendar_source_id');
        });
    }
};
