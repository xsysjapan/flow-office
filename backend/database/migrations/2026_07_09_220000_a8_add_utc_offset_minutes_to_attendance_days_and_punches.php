<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤務実績(attendance_days)・打刻ログ(attendance_punches)に、その勤務日/打刻に
 * 適用されたUTCオフセット(分)を保持する列を追加する。
 *
 * 海外出張などで深夜残業の判定に使う「現地時刻」が勤務日ごとに変わるため、
 * ユーザーの既定タイムゾーン(users.timezone)とは別に、勤務実績そのものが
 * 自分自身のオフセットを持つ (docs/03-architecture.md 3.4)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->integer('utc_offset_minutes')->default(540)->after('actual_end_at');
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->integer('utc_offset_minutes')->default(540)->after('punched_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn('utc_offset_minutes');
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropColumn('utc_offset_minutes');
        });
    }
};
