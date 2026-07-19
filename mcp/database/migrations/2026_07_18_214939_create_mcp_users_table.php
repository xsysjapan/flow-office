<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mcp/ 上での人間ユーザー。backend/のusersテーブルとは独立した別データであり、
 * mcp_user_backend_tokens経由でbackendの個人連携Sanctumトークンと紐付ける。
 * このアプリ自体は認証手段(パスワード等)を持たず、識別子はbackendから返される
 * メールアドレス(GET /auth/me の結果)を使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('display_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_users');
    }
};
