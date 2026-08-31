<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 貸出申請(request_types.code=asset_loan)の読み取り専用Projection(spec 論点2)。
 * 書き込みロジックは一切持たず、workflow_requests側のイベント(request_type=asset_loanの
 * もの)とasset.loaned(loanRequestIdあり)を購読するReactorのみが更新する。
 * idはworkflow_requests.idと同一(1対1)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_loan_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained('assets');
            $table->foreignUuid('applicant_user_id')->constrained('users');
            $table->foreignUuid('approver_user_id')->nullable()->constrained('users');
            // pending/approved/rejected/withdrawn/cancelled/lent
            $table->string('status');
            $table->text('purpose')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('lent_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['applicant_user_id', 'status']);
            $table->index(['approver_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_loan_requests');
    }
};
