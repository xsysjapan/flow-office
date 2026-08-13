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
            // 規約通りの外部キー名(calendar_bulk_operation_targets_calendar_bulk_operation_id_foreign /
            // calendar_bulk_operation_targets_employee_calendar_entry_id_foreign)はいずれも66文字で
            // MySQLの識別子長上限(64文字)を超えるため、明示的に短い名前を指定する。
            $table->foreignUuid('calendar_bulk_operation_id')
                ->constrained('calendar_bulk_operations', indexName: 'calendar_bulk_operation_targets_operation_id_foreign')
                ->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained();
            $table->date('work_date');
            $table->foreignUuid('employee_calendar_entry_id')
                ->nullable()
                ->constrained('employee_calendar_entries', indexName: 'calendar_bulk_operation_targets_entry_id_foreign')
                ->nullOnDelete();
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
