<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 区分固有の入力項目定義(「経費精算機能 設計・実装指示書」7.2 ExpenseTypeDefinition相当)。
 * [{key, label, type, required, options?}, ...] の配列。expense_items.attributesに
 * 保存できるキーをここで定義したものだけに限定する。バージョン管理は行わず、常に最新の
 * 定義で検証・表示する(既存申請の値自体はそのまま保持されるため表示は破綻しない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->json('field_definitions')->nullable()->after('entry_mode');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('field_definitions');
        });
    }
};
