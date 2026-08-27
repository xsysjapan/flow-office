<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoneyForwardクラウド経費API(ex_transactions)修正対応。ex_item_id(経費科目id)・
 * dr_excise_id(税区分id)はコードではなくMoneyForward内部の管理IDであるため、
 * flow-office側の`expense_categories.account_code`/`tax_category`からMoneyForward内部IDへの
 * マッピングを保持する(docs/notes/moneyforward-api-investigation.md)。連携先が増えた場合にも
 * 使えるよう provider 単位で保持する、小さな汎用マッピングテーブル。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_account_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider'); // moneyforward (将来的に他連携先でも利用可能)
            $table->string('mapping_type'); // ex_item(経費科目) / dr_excise(税区分)
            $table->string('source_code'); // expense_categories.account_code もしくは tax_category
            $table->string('external_id'); // MoneyForward側の内部ID
            $table->timestamps();

            $table->unique(
                ['provider', 'mapping_type', 'source_code'],
                'external_account_mappings_provider_type_code_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_account_mappings');
    }
};
