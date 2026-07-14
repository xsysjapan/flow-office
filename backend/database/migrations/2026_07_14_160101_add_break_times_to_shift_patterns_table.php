<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * シフトパターンの休憩を「合計分数」だけでなく開始・終了時刻でも持ち、日次勤怠の
 * 初期表示(勤務予定の休憩を含めて反映する)に使えるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_patterns', function (Blueprint $table) {
            $table->time('break_start_time')->nullable()->after('break_minutes');
            $table->time('break_end_time')->nullable()->after('break_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('shift_patterns', function (Blueprint $table) {
            $table->dropColumn(['break_start_time', 'break_end_time']);
        });
    }
};
