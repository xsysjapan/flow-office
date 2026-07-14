<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 打刻を日次実績の初期値に反映する際の丸め単位(働き方ごと)と、標準休憩の開始・終了時刻
 * (勤務予定・打刻が無い日にシステムの初期設定として使う)をwork_stylesに追加する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            // 5/10/15/30のいずれか。null(または1)は丸めなし。
            $table->unsignedTinyInteger('rounding_unit_minutes')->nullable()->after('default_break_minutes');
            $table->time('default_break_start_time')->nullable()->after('rounding_unit_minutes');
            $table->time('default_break_end_time')->nullable()->after('default_break_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn(['rounding_unit_minutes', 'default_break_start_time', 'default_break_end_time']);
        });
    }
};
