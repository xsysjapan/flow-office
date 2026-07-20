<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退勤時に標準休憩(default_break_start_time〜default_break_end_time)を自動で
 * attendance_breaksへ挿入するかどうかを働き方ごとに切り替えるフラグを追加する。
 * 打刻に休憩が一切含まれない場合のみ適用する自動補完であり、実際に休憩を打刻・編集した
 * 日には影響しない(ClockOutHandler参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->boolean('auto_break_enabled')->default(false)->after('default_break_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn('auto_break_enabled');
        });
    }
};
