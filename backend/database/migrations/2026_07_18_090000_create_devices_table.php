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
            $table->id();
            // ESの集約ストリームID。attendance_punches.device_id等が主キー(連番int)を
            // 参照しているため主キー自体はUUID化せず、別列でストリーム識別子を持つ
            // (docs/29-event-sourcing-framework-migration.md「集約IDのUUID化方針」(a)参照)。
            $table->uuid('aggregate_uuid')->unique();
            $table->string('owner_type');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
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
