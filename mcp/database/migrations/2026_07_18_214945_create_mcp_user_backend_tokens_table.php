<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * backend/ の POST /users/me/integrations (UC-I001) で発行された個人連携Sanctumトークンを、
 * mcp_userに紐付けて保持する(暗号化して保存)。mcp/自身はbackendのDBに触れず、この
 * トークンを使ってHTTP経由でbackend APIを呼び出すだけのクライアントである。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_user_backend_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_user_id')->constrained('mcp_users')->cascadeOnDelete();
            $table->text('encrypted_token');
            $table->json('granted_scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_user_backend_tokens');
    }
};
