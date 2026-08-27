<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 経費・勤怠の外部連携基盤(フェーズ1)。会計CSV出力(freee/MoneyForward形式)・証跡アーカイブ
 * Excelで参照する勘定科目・税区分マスタ列を expense_categories に追加する。
 * - account_code: freee/MoneyForward等の会計クラウドに取り込む際の勘定科目コード。
 * - tax_category: 税区分(インボイス制度の適格請求書等区分を含む)。法務判断が必要な値
 *   のため区分マスタ側に持たせ、コードにハードコードしない(CLAUDE.md 設計原則8)。
 * 既存の field_definitions(区分固有の入力項目定義, JSON)の運用には影響しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('account_code')->nullable()->after('field_definitions');
            $table->string('tax_category')->nullable()->after('account_code');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn(['account_code', 'tax_category']);
        });
    }
};
