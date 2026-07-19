<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->foreignId('mcp_user_id')->constrained('mcp_users')->cascadeOnDelete();
            $table->json('scopes');
            $table->string('redirect_uri');
            $table->string('code_challenge');
            $table->string('code_challenge_method');
            $table->dateTime('expires_at');
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_auth_codes');
    }
};
