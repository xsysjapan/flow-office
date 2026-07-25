<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 打刻の丸め単位(rounding_unit_minutes)に加えて、丸め方向を設定できるようにする。
 * 四捨五入(nearest)・切り捨て(shorten、勤務時間が短くなる方向)・
 * 切り上げ(lengthen、勤務時間が長くなる方向)のいずれか。既存データは四捨五入(nearest)
 * として扱う(AttendanceDayDefaultsResolver::resolveRoundingMode()参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->string('rounding_mode')->nullable()->after('rounding_unit_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn('rounding_mode');
        });
    }
};
