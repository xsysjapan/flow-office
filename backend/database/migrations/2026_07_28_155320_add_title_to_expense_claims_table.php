<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 経費申請のタイトル(「経費精算機能 設計・実装指示書」5.2)。任意項目とし、下書き作成時には
 * 入力させない(対象期間と同様、無意味な入力を強制しないUC-X004の方針を維持する)。
 * 未設定の場合は画面側で対象期間から表示名を組み立てる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->string('title')->nullable()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
