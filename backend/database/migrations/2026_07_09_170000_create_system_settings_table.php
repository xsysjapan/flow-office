<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * システム全体の設定 (docs/06-usecases-auth.md UC-003)。常に1行のみ存在する
 * シングルトンのマスタで、新規ユーザーのデフォルトタイムゾーンなどを保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_timezone')->default('Asia/Tokyo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
