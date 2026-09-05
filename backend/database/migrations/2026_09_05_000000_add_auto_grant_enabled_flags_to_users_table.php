<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 有給・特別休暇の自動付与をユーザーごとに個別に無効化できるようにする
 * (docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。
 * デフォルトtrueとし、既存社員の自動付与挙動は変化しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('paid_leave_auto_grant_enabled')->default(true)->after('usage_start_date');
            $table->boolean('special_leave_auto_grant_enabled')->default(true)->after('paid_leave_auto_grant_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['paid_leave_auto_grant_enabled', 'special_leave_auto_grant_enabled']);
        });
    }
};
