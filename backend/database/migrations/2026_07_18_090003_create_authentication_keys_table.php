<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 認証キー(docs/24-usecases-authentication-keys.md)。生の値は保存せず、
 * HMAC-SHA256でハッシュ化した`key_hash`のみを保存する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authentication_keys', function (Blueprint $table) {
            $table->id();
            // ESの集約ストリームID。device_admin_sessions.authentication_key_id等が主キー
            // (連番int)を参照しているため主キー自体はUUID化せず、別列でストリーム識別子を持つ
            // (docs/29-event-sourcing-framework-migration.md「集約IDのUUID化方針」(a)参照)。
            $table->uuid('aggregate_uuid')->unique();
            $table->foreignId('user_id')->constrained();
            $table->string('key_type');
            $table->string('display_name');
            $table->string('key_hash', 64);
            $table->string('status')->default('active');
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->json('metadata_json')->nullable();
            $table->foreignId('registered_by_user_id')->constrained('users');
            $table->dateTime('registered_at');
            $table->dateTime('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['key_type', 'key_hash']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_keys');
    }
};
