<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 社員単位の現在残高キャッシュ(PaidLeaveAccountAggregateのGrant/Usage/Allocationイベントから
 * 都度再集計するProjection。表示専用であり、承認可否等の業務判定の根拠には使わない
 * ―判定は必ずCommandHandlerがAggregateをreplayして行う)。
 * next_grant_scheduled_onはPaidLeaveScheduleドメイン(Phase 7以降)導入までnullのまま置く。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_balances', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained();
            $table->decimal('available_days', 5, 1)->default(0);
            $table->decimal('pending_days', 5, 1)->default(0);
            $table->decimal('unallocated_days', 5, 1)->default(0);
            $table->date('next_grant_scheduled_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_balances');
    }
};
