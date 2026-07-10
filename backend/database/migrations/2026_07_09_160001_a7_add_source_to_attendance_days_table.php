<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * どの経路で最後に確定したかを記録する (docs/07-usecases-attendance.md UC-A012)。
 * live: 画面の出勤/退勤ボタン、manual: 日次編集、punch: 打刻ログからの自動反映。
 * punch以外の経路で確定した日は、打刻ログが後から届いても上書きしない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->string('source')->default('live')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
