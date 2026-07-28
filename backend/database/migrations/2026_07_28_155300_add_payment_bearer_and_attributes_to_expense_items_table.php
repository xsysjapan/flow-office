<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 経費明細の共通フォーマットを拡張する。
 * - payment_bearer: 誰が支払ったか(個人立替/法人カード等)。法人カード等の場合は
 *   reimbursement_amount(会社から社員への返金額)を0にするために必要
 *   (「経費精算機能 設計・実装指示書」6.4参照)。
 * - attributes: 区分固有の構造化情報(交通手段・出発地・宿泊先名等)。区分ごとの専用カラム/
 *   専用テーブルを作らず、expense_categories.field_definitionsで定義したキーのみを許可する
 *   (同指示書6.5)。descriptionは引き続き人が読む要約として残す。
 * - reimbursement_amount: 派生値。payment_bearerがemployeeの場合のみ
 *   (amount - commuting_deduction_amount)、それ以外は0。ExpenseClaimProjectorが
 *   明細追加・修正時に都度再計算する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->string('payment_bearer')->default('employee')->after('commuting_deduction_amount');
            $table->unsignedInteger('reimbursement_amount')->default(0)->after('payment_bearer');
            $table->json('attributes')->nullable()->after('reimbursement_amount');
        });
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->dropColumn(['payment_bearer', 'reimbursement_amount', 'attributes']);
        });
    }
};
