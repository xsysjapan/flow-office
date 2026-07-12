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
        Schema::table('work_styles', function (Blueprint $table) {
            // 3交代制など(is_shift_based)の連続勤務日数の警告しきい値。法令上の一律の上限は
            // 無いため会社の就業規則次第でマスタ化する(null=チェックしない)。UC-C004参照。
            $table->unsignedTinyInteger('max_consecutive_work_days')->nullable()->after('legal_holiday_rule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn('max_consecutive_work_days');
        });
    }
};
