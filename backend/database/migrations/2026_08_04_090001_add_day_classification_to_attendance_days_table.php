<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 日次勤怠の区分(`App\Models\DayClassification`: working_day / prescribed_holiday /
 * legal_holiday)。`AttendanceCalculator`が`employee_shift_assignments.day_type`と同じ判定
 * ロジック(is_legal_holiday/is_company_holiday)から算出し、日次計算のたびに保存し直す
 * 派生データ(`AttendanceDailyCalculationProjector`経由で再生成可能)。既存データは次回の
 * 日次計算再実行時に埋まる想定のため、ここでのバックフィルは行わない(nullable許容)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->string('day_classification')->nullable()->after('work_type');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn('day_classification');
        });
    }
};
