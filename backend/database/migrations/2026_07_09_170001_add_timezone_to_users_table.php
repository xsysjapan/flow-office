<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ユーザーごとにタイムゾーンを保持する (docs/06-usecases-auth.md UC-003)。
 * 新規作成時はシステム設定のデフォルトタイムゾーンを使う。既存ユーザーの
 * タイムゾーンはMS365同期で上書きしない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->default('Asia/Tokyo')->after('employment_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
