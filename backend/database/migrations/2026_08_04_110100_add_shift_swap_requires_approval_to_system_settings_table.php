<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 振替休日申請を承認ワークフロー無しで即時確定(承認不要)にするかどうかのシステム全体設定。
 * 既定はtrue(承認必須。special_leave_requires_approval等と同じ考え方)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('shift_swap_requires_approval')->default(true)->after('expense_claim_requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('shift_swap_requires_approval');
        });
    }
};
