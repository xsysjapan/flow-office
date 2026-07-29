<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「経費精算機能 設計・実装指示書」9〜10: 入力プリセット。個人(personal)・全社共有(company)・
 * システム標準(system)は`visibility`の違いのみで表現し、テーブル構造・振る舞いを分けない
 * (expense_route_templatesと同じ考え方)。イベントソーシング対象外の通常のEloquent CRUD。
 * definitionは「経費項目1件分の下書き」の配列(category_id・descriptionの初期値・amountの
 * 初期値・payment_bearerの初期値・attributesの初期値)。任意のコード・SQL・外部API呼び出しは
 * 持たせず、単純なデータ定義に限定する(指示書10.1)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_entry_presets', function (Blueprint $table) {
            $table->id();
            // personal / company / system
            $table->string('visibility');
            // personalスコープの所有者。company/systemはnull。
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users');
            $table->string('name');
            $table->text('description')->nullable();
            // single_item / multiple_items (表示上の分類。適用処理自体はdefinitionの件数に従うため共通)
            $table->string('preset_type');
            $table->json('definition');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['visibility', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_entry_presets');
    }
};
