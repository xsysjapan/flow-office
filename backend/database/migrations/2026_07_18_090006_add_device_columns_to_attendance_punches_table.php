<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 打刻元の端末・認証キー・操作主体・冪等性キーを記録する(docs/23-usecases-devices.md、
 * docs/24-usecases-authentication-keys.md、docs/03-architecture.md 3.5)。
 * 「どこから打刻したか」の正の記録。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignUuid('device_id')->nullable()->after('source')->constrained()->nullOnDelete();
            $table->foreignUuid('authentication_key_id')->nullable()->after('device_id')->constrained()->nullOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->after('authentication_key_id')->constrained('users')->nullOnDelete();
            $table->uuid('integration_id')->nullable()->after('actor_user_id');
            $table->boolean('offline')->default(false)->after('integration_id');
            $table->string('idempotency_key')->nullable()->unique()->after('offline');
            $table->string('request_id')->nullable()->after('idempotency_key');
            $table->json('metadata_json')->nullable()->after('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropConstrainedForeignId('authentication_key_id');
            $table->dropConstrainedForeignId('actor_user_id');
            $table->dropColumn(['integration_id', 'offline', 'idempotency_key', 'request_id', 'metadata_json']);
        });
    }
};
