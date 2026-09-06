<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PaidLeaveAccountAggregateの`PaidLeaveUsageAllocated`/`PaidLeaveUsageAllocationReleased`
 * イベントから作成・更新されるProjection。UsageとGrantの充当関係(Allocation)の
 * Source of Truthであり、`paid_leave_grants.allocated_days`/`remaining_days`や
 * `paid_leave_balances`はここからの非正規化キャッシュに過ぎない
 * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md 論点7)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_usage_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('usage_id');
            $table->uuid('grant_id');
            $table->decimal('allocated_days', 4, 1);
            $table->timestamps();

            $table->unique(['usage_id', 'grant_id']);
            $table->index('grant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_usage_allocations');
    }
};
