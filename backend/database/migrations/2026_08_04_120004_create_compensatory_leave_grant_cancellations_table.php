<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 代休Grant(未使用分のみ)の取消申請。日次勤怠の消化申請ではなくGrantそのものの取消が
 * 対象のため、workflow_requests連携までは行わずこの専用テーブルで最小限に管理する
 * (compensatory_leave_grantsの状態変更自体はCompensatoryLeaveGrantAggregate経由で
 * イベントソーシングする。このテーブルは申請・承認の進捗管理のみを担う)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensatory_leave_grant_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('grant_id')->constrained('compensatory_leave_grants');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->foreignUuid('approver_user_id')->nullable()->constrained('users');
            $table->string('status')->default('pending'); // pending, approved
            $table->text('reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['grant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensatory_leave_grant_cancellations');
    }
};
