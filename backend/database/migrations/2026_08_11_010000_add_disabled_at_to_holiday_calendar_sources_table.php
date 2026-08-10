<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * UC-C012 手順5: ソースを無効化すると以後の自動同期は停止するが、既に反映済みの祝日
     * データは保持する。`sync_status`(pending/synced/failed)とは別軸の状態のため列を分ける。
     */
    public function up(): void
    {
        Schema::table('holiday_calendar_sources', function (Blueprint $table) {
            $table->dateTime('disabled_at')->nullable()->after('last_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('holiday_calendar_sources', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
