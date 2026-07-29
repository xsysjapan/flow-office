<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-X004〜UC-X009: 経費明細。通勤費・業務交通費・その他経費すべてを1つのテーブルで表現する
 * (docs/30-usecases-expense.md「実装上のポイント」)。主キーはexpense_claim集約が発行する
 * itemIdをそのまま使うUUID(ExpenseClaimProjectorが作成・更新する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('claim_id')->constrained('expense_claims')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->date('usage_date')->nullable();
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->string('transport_type')->nullable();
            $table->unsignedInteger('amount');
            $table->string('destination_name')->nullable();
            $table->text('purpose')->nullable();
            $table->string('project_id')->nullable();
            // fact_reference_available / receipt_required / receipt_optional
            $table->string('evidence_type');
            $table->string('fact_reference_type')->nullable();
            $table->string('fact_reference_id')->nullable();
            $table->unsignedInteger('commuting_deduction_amount')->default(0);
            $table->timestamps();

            $table->index('claim_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_items');
    }
};
