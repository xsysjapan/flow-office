<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 提出時ロック・差戻し解除(UC-X010/UC-X011)。提出するとclaim全体(明細含む)が編集不可になり、
 * 差戻しされるまで解除されない。明細(expense_items)は親claimのロック状態を見るだけなので
 * 個別のロックカラムは持たない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('submitted_at');
            $table->timestamp('unlocked_at')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn(['locked_at', 'unlocked_at']);
        });
    }
};
