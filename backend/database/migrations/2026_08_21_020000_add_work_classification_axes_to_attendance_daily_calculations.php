<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'prescribed_statutory_within_work_minutes',
        'non_prescribed_statutory_within_work_minutes',
        'prescribed_statutory_excess_work_minutes',
        'non_prescribed_statutory_excess_work_minutes',
        'late_night_prescribed_statutory_within_work_minutes',
        'late_night_non_prescribed_statutory_within_work_minutes',
        'late_night_prescribed_statutory_excess_work_minutes',
        'late_night_non_prescribed_statutory_excess_work_minutes',
    ];

    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->unsignedSmallInteger($column)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }
};
