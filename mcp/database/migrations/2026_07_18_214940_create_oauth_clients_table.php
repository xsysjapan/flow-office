<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic Client Registration (RFC 7591) で登録されるMCPクライアント(Claude等)。
 * 公開クライアント(client_secretなし、PKCE必須)のみを扱う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->unique();
            $table->string('client_name');
            $table->json('redirect_uris');
            $table->json('grant_types');
            $table->string('token_endpoint_auth_method')->default('none');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
