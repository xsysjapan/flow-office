<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-X001: 経費区分マスタ。イベントソーシング対象外の通常のEloquent CRUD。
 * 区分ごとの証憑要否・承認省略ルールはすべてこのマスタの設定であり、区分追加のために
 * コードを変更しない(docs/30-usecases-expense.md)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // fact_reference_available / receipt_required / receipt_optional
            $table->string('evidence_type_default')->default('receipt_optional');
            $table->unsignedInteger('receipt_required_threshold')->nullable();
            $table->unsignedInteger('approval_skip_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
