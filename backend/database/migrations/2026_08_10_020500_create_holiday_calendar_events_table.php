<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 祝日iCalendar同期結果(docs/16-database-schema.md holiday_calendar_events、UC-C012)。
     * 同期処理自体は次のタスクで実装するため、今回はテーブル・モデルのみ作成する。
     */
    public function up(): void
    {
        Schema::create('holiday_calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('holiday_calendar_source_id')->constrained('holiday_calendar_sources')->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->string('ics_uid');
            $table->dateTime('synced_at');
            $table->timestamps();

            // 規約通りの名前(holiday_calendar_events_holiday_calendar_source_id_ics_uid_unique)は
            // 67文字でMySQLの識別子長上限(64文字)を超えるため、明示的に短い名前を指定する。
            $table->unique(['holiday_calendar_source_id', 'ics_uid'], 'holiday_calendar_events_source_uid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holiday_calendar_events');
    }
};
