<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 祝日iCalendarソース(docs/16-database-schema.md holiday_calendar_sources、UC-C012)。
     * 同期処理自体は次のタスクで実装するため、今回はテーブル・モデルのみ作成する。
     */
    public function up(): void
    {
        Schema::create('holiday_calendar_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('ics_url');
            $table->string('sync_status')->default('pending'); // pending, synced, failed
            $table->dateTime('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holiday_calendar_sources');
    }
};
