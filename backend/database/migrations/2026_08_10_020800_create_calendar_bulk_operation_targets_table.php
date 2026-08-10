<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 一括操作の対象明細(docs/16-database-schema.md calendar_bulk_operation_targets、UC-C013)。
     * 適用ロジック自体は次のタスクで実装するため、今回はテーブル・モデルのみ作成する。
     */
    public function up(): void
    {
        Schema::create('calendar_bulk_operation_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('calendar_bulk_operation_id')->constrained('calendar_bulk_operations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained();
            $table->date('work_date');
            $table->foreignUuid('employee_calendar_entry_id')->nullable()->constrained('employee_calendar_entries')->nullOnDelete();
            $table->string('result'); // applied, skipped_existing, failed
            $table->string('error_code')->nullable();
            $table->json('previous_snapshot')->nullable();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_bulk_operation_targets');
    }
};
