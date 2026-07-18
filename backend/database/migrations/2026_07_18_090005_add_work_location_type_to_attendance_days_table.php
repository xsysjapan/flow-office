<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤務形態区分(docs/07-usecases-attendance.md「勤務形態区分」)。既存の`work_type`
 * (有給区分)とは別軸のため列を分ける。時間計算には一切影響しない分類情報。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->string('work_location_type')->nullable()->after('work_type');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn('work_location_type');
        });
    }
};
