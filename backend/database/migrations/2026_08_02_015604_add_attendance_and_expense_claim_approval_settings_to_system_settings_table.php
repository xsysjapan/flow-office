<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 月次勤怠・経費精算の申請を承認ワークフロー無しで即時確定(承認不要)にするかどうかの
 * システム全体設定。既定はtrue(承認必須。現行の挙動を維持する)。paid_leave_requires_approval・
 * special_leave_requires_approvalと同じ考え方(2026_08_01_090000_...php参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('attendance_requires_approval')->default(true)->after('special_leave_requires_approval');
            $table->boolean('expense_claim_requires_approval')->default(true)->after('attendance_requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['attendance_requires_approval', 'expense_claim_requires_approval']);
        });
    }
};
