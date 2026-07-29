<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-X004: expense_claims.period_from/period_to は明細のusage_dateの最小値・最大値から
 * ExpenseClaimProjectorが算出する派生値に変わり、ユーザー入力欄ではなくなった
 * (docs/30-usecases-expense.md)。明細0件の下書き作成時点では値が存在しないためnullable化する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->date('period_from')->nullable()->change();
            $table->date('period_to')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->date('period_from')->nullable(false)->change();
            $table->date('period_to')->nullable(false)->change();
        });
    }
};
