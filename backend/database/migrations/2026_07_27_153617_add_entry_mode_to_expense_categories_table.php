<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-X001/UC-X004: 経費区分ごとに入力方式(entry_mode)を設定できるようにする。
 * batch = 交通費専用のまとめ入力ツール(表形式・移動経路・テンプレート)、
 * single = 区分専用の1件入力フォームを繰り返し使う(docs/30-usecases-expense.md)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('entry_mode')->default('single')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('entry_mode');
        });
    }
};
