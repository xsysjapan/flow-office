<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 会社カレンダー日の生成元履歴(docs/16-database-schema.md company_calendar_day_sources)。
     * ドメインロジック(祝日同期・一括操作の書き込み)は次のタスクで実装するため、
     * 今回はテーブル・モデルのみ作成する。
     */
    public function up(): void
    {
        Schema::create('company_calendar_day_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_calendar_day_id')->constrained('company_calendar_days')->cascadeOnDelete();
            $table->string('source_type'); // standard_template, holiday_sync, manual
            $table->string('source_ref')->nullable();
            $table->dateTime('applied_at');
            $table->foreignUuid('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_calendar_day_sources');
    }
};
