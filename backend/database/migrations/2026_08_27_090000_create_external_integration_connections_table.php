<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤怠API連携(フェーズ2、docs/33-usecases-attendance-external-api.md)。freee人事労務
 * (OAuth2.0)・マネーフォワードクラウド給与(APIキー)ごとに1レコード持つ認可情報テーブル。
 *
 * `application_integrations`(docs/25-usecases-integrations-mcp.md、AIアプリ・MCP向けの
 * Sanctumトークン発行台帳)とは別概念。本テーブルはflow-office自身が外部の会計・労務クラウド
 * APIを「呼び出す側」として使う認可情報であり、flow-officeへ「呼び出される側」の
 * application_integrationsとは責務が異なるため独立したテーブルとする。
 *
 * トークン・APIキーは平文で保存せず、Laravelの`encrypted`キャストで暗号化する
 * (SystemSetting::m365_client_secretと同じ方式)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_integration_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider')->unique(); // freee, moneyforward
            $table->string('auth_type'); // oauth2, api_key
            $table->string('status')->default('active'); // active, disconnected
            $table->text('access_token')->nullable(); // encrypted
            $table->text('refresh_token')->nullable(); // encrypted
            $table->text('api_key')->nullable(); // encrypted
            $table->text('client_id')->nullable(); // encrypted (OAuth2アプリ登録情報)
            $table->text('client_secret')->nullable(); // encrypted
            $table->timestamp('token_expires_at')->nullable();
            $table->foreignUuid('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_integration_connections');
    }
};
