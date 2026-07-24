<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 端末マスタ(docs/23-usecases-devices.md、docs/16-database-schema.md)。
 * 共有Android打刻リーダー・個人端末・外部端末を共通のテーブルで扱う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする。行の新規作成自体もDeviceProjector経由で
            // 行えるようにするため(docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->string('owner_type');
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('device_type');
            $table->string('status')->default('pending_pairing');
            $table->string('site_id')->nullable();
            $table->string('location_name')->nullable();
            $table->string('default_work_location_type')->nullable();
            $table->string('timezone')->nullable();
            $table->json('allowed_punch_types')->nullable();
            $table->boolean('allow_offline')->default(true);
            $table->boolean('require_location')->default(false);
            $table->boolean('auto_detect_punch_type')->default(false);
            $table->string('app_version')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('paired_at')->nullable();
            $table->dateTime('disabled_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
