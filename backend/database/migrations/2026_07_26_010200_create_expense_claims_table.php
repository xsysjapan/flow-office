<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-X010〜UC-X012: 経費精算ヘッダー。expense_claim集約(spatie/laravel-event-sourcing)の
 * 主キーはコマンド側生成のUUID(この行自体もExpenseClaimProjectorが作成・更新する。
 * workflow_requests/backoffice_tasksと同じ理由。docs/29-event-sourcing-framework-migration.md参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('users');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status')->default('draft');
            $table->foreignUuid('approver_user_id')->nullable()->constrained('users');
            $table->unsignedInteger('total_amount')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['approver_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
    }
};
