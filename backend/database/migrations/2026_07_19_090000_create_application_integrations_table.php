<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 個人/組織のAPI・MCP連携(docs/25-usecases-integrations-mcp.md、
 * docs/16-database-schema.md)。実際の認証キーの実体はSanctumの
 * personal_access_tokensに委譲する(user_credentials/devicesと同じ考え方)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_integrations', function (Blueprint $table) {
            $table->id();
            // ESの集約ストリームID。attendance_punches.integration_idが主キー(連番int)を
            // 参照しているため主キー自体はUUID化せず、別列でストリーム識別子を持つ
            // (docs/29-event-sourcing-framework-migration.md「集約IDのUUID化方針」(a)参照)。
            // ApplicationIntegrationAggregateが発番し、行の新規作成含めてIntegrationProjectorが
            // このUUIDをキーに作成・更新する。
            $table->uuid('aggregate_uuid')->unique();
            $table->string('owner_type');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_type');
            $table->string('client_name');
            $table->text('purpose')->nullable();
            $table->foreignId('personal_access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('status')->default('active');
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('registered_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['owner_type', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_integrations');
    }
};
