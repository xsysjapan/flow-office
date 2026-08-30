<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_loans', function (Blueprint $table) {
            // asset.loanedイベントのloanIdをそのまま主キーにする(AssetProjectorが
            // イベント適用時に確定できるようにするため)。
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            // approval方式で貸与された場合のみworkflow_requests.idを指す(nullable)。
            // 現時点ではworkflow_requests側との連携(request_types=asset_loan)は
            // 別フェーズで実装するため、外部キー制約は付けない。
            $table->uuid('loan_request_id')->nullable();
            $table->timestamp('loaned_at');
            $table->timestamp('expected_return_at')->nullable();
            $table->foreignUuid('loaned_by_user_id')->constrained('users');
            $table->timestamp('returned_at')->nullable();
            $table->foreignUuid('returned_by_user_id')->nullable()->constrained('users');
            $table->text('return_note')->nullable();

            $table->index(['asset_id', 'returned_at']);
            $table->index(['user_id', 'returned_at']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('current_loan_id')->references('id')->on('asset_loans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['current_loan_id']);
        });
        Schema::dropIfExists('asset_loans');
    }
};
