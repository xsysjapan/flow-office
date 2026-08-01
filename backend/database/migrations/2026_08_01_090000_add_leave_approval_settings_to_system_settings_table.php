<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 有給・特別休暇の申請を承認ワークフロー無しで即時消化(自動承認)にするかどうかの
 * システム全体設定。既定はtrue(承認必須。現行の挙動を維持する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('paid_leave_requires_approval')->default(true)->after('m365_client_secret');
            $table->boolean('special_leave_requires_approval')->default(true)->after('paid_leave_requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['paid_leave_requires_approval', 'special_leave_requires_approval']);
        });
    }
};
