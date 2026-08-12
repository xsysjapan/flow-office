<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 祝日iCalendar同期(UC-C012)の結果概要(追加・更新・削除・反映件数・保護競合件数)を
     * フロントエンドに表示できるようにするためのカラム。
     */
    public function up(): void
    {
        Schema::table('holiday_calendar_sources', function (Blueprint $table) {
            $table->json('last_sync_summary')->nullable()->after('last_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('holiday_calendar_sources', function (Blueprint $table) {
            $table->dropColumn('last_sync_summary');
        });
    }
};
