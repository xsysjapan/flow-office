<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 初回オンボーディング(docs/06-usecases-auth.md UC-000)の開始を原子的にクレームするための
 * タイムスタンプ。`onboarding_completed_at`とは別に持つことで、SSOモード(設定保存→実際の
 * Entra IDログイン待ち、という2リクエストにまたがる状態)の途中経過を表現できるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->timestamp('onboarding_started_at')->nullable()->after('m365_mock_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('onboarding_started_at');
        });
    }
};
