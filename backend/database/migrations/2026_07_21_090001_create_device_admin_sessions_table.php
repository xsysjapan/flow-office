<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 端末(共有Androidリーダー等)が管理者モードに入っている期間を記録する
 * (docs/23-usecases-devices.md UC-D006「Android端末を管理者モードにする」)。
 * 管理者本人のICカード(認証キー)をかざして開始し、社員証NFC登録などの管理操作を
 * この期間中のみ許可する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_admin_sessions', function (Blueprint $table) {
            // 集約ID(aggregate_uuid)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする(他テーブルから参照されないため、
            // Attachment/WorkflowRequestと同じ(b)方式。docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('authentication_key_id')->nullable()->constrained('authentication_keys')->nullOnDelete();
            $table->string('source');
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'ended_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_admin_sessions');
    }
};
