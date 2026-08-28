<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 外部連携(freee/マネーフォワード)設定を管理画面から複数登録・有効化できるようにする対応。
 * 従来はproviderごとに1件・シーダー/DB直接投入前提だったが、今回から:
 * - provider の unique 制約を外し、同一providerで複数登録できるようにする
 *   (例: 部署ごとに異なるfreee事業所と契約している場合など)。
 * - name(表示名)を追加し、一覧画面で複数件を区別できるようにする。
 * - enabled(ユーザーが「有効化」した意思)を status(接続確立状態)とは別軸で持つ。
 *   接続テスト等は対象外のため、登録時の status は引き続き active 固定とする。
 * - custom_settings(プロバイダ固有の追加設定、勤怠計算に影響しないメタ情報のみ)を追加する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_integration_connections', function (Blueprint $table) {
            $table->dropUnique(['provider']);
            $table->string('name')->after('provider')->default('');
            $table->boolean('enabled')->default(false)->after('status');
            $table->json('custom_settings')->nullable()->after('client_secret');
        });
    }

    public function down(): void
    {
        Schema::table('external_integration_connections', function (Blueprint $table) {
            $table->dropColumn(['name', 'enabled', 'custom_settings']);
            $table->unique('provider');
        });
    }
};
