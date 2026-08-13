<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 複数従業員予定の一括操作(docs/16-database-schema.md calendar_bulk_operations、UC-C013)。
     * 適用ロジック自体は次のタスクで実装するため、今回はテーブル・モデルのみ作成する。
     */
    public function up(): void
    {
        Schema::create('calendar_bulk_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('operation_type'); // calendar_apply, rotation_generate, bulk_edit
            $table->json('target_scope');
            $table->string('conflict_policy')->default('skip_existing'); // skip_existing, overwrite, fail_on_conflict
            $table->string('status')->default('applied'); // applied, reverted
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('reverted_at')->nullable();
            $table->text('reason');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_bulk_operations');
    }
};
