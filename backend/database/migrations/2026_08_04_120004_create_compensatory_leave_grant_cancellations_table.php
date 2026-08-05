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
        // MySQLの識別子は64文字までだが、自動生成される制約名
        // `compensatory_leave_grant_cancellations_requested_by_user_id_foreign`は67文字で
        // これを超えるため、外部キー制約名を明示的に短く指定する。デプロイ時にこの制約作成で
        // 一度失敗し、テーブル自体は作成済みだがmigrationsには未実行として残った状態になり
        // 得るため、作り直せるようdropIfExistsしてから作成する(本番未稼働の新規テーブルの
        // ため、既存データを失う心配はない)。
        Schema::dropIfExists('compensatory_leave_grant_cancellations');

        Schema::create('compensatory_leave_grant_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('grant_id')->constrained('compensatory_leave_grants', 'id', 'clgc_grant_id_foreign');
            $table->foreignUuid('requested_by_user_id')->constrained('users', 'id', 'clgc_requested_by_user_id_foreign');
            $table->foreignUuid('approver_user_id')->nullable()->constrained('users', 'id', 'clgc_approver_user_id_foreign');
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
