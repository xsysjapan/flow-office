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
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする。行の新規作成自体もAuthenticationKeyProjector
            // 経由で行えるようにするため(docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->string('key_type');
            $table->string('display_name');
            $table->string('key_hash', 64);
            $table->string('status')->default('active');
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->json('metadata_json')->nullable();
            $table->foreignUuid('registered_by_user_id')->constrained('users');
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
