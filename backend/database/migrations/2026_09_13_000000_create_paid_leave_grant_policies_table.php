<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `2026_09_09_100000_create_paid_leave_grant_policies_table.php`が作った旧スキーマ
     * (`version`が整数・`effective_from`/`is_active`列なし)を、法定通常付与表の新スキーマへ
     * 置き換える。本番は既に旧スキーマでこのテーブルが稼働中(spec.md論点4の初期実装)のため、
     * 単純な`Schema::create`では「table already exists」で失敗する。
     *
     * 旧テーブルを一時退避(`Schema::rename`)→新スキーマで作成→旧データを新形式へ変換して
     * コピー(`version`は整数から`v<N>`形式の文字列へ、`effective_from`/`is_active`は
     * 新規追加のため決め打ち値で補完)→旧テーブル削除、という手順で、CREATE IF NOT EXISTS等の
     * 場当たり対応ではなく、実際のデータを保持したまま構造を移行する。
     *
     * 本番・フレッシュ環境のいずれでも、直前の`2026_09_09_100000_...`マイグレーションで
     * このテーブルが必ず存在する状態から始まるため(本番は既存、フレッシュ環境は直前の
     * マイグレーションで新規作成済み)、`Schema::hasTable`等の分岐は不要。
     */
    public function up(): void
    {
        Schema::rename('paid_leave_grant_policies', 'paid_leave_grant_policies_legacy');

        Schema::create('paid_leave_grant_policies', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->unsignedInteger('continuous_service_months');
            $table->decimal('grant_days', 4, 1);
            $table->date('effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['version', 'continuous_service_months'], 'paid_leave_grant_policies_version_months_unique');
            $table->index(['is_active']);
        });

        foreach (DB::table('paid_leave_grant_policies_legacy')->orderBy('id')->get() as $legacyRow) {
            DB::table('paid_leave_grant_policies')->insert([
                'version' => 'v'.$legacyRow->version,
                'continuous_service_months' => $legacyRow->continuous_service_months,
                'grant_days' => $legacyRow->grant_days,
                'effective_from' => null,
                'is_active' => true,
                'created_at' => $legacyRow->created_at,
                'updated_at' => $legacyRow->updated_at,
            ]);
        }

        Schema::dropIfExists('paid_leave_grant_policies_legacy');
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grant_policies');
    }
};
