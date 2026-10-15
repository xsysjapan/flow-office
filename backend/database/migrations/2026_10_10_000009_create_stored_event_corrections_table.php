<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stored_events の直接修正(書き換え・削除)の補正ログ(App\Domain\EventSourcing\Correction\StoredEventCorrector)。
 *
 * 修正したイベントのID・修正前のpayload・修正後のpayload・バックアップテーブル名を残し、同じ補正キーの
 * 再実行で修正済みの行を再修正しないための冪等の記録に使う。Projectionではないためリビルドの対象外。
 * 補正ログの行は削除しない(補正の追跡に使う)。stored_event_idには外部キーを張らない(削除された行も残すため)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_event_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('correction_key', 64);
            $table->unsignedBigInteger('stored_event_id');
            $table->string('operation', 16);
            $table->uuid('aggregate_uuid')->nullable();
            $table->unsignedBigInteger('aggregate_version')->nullable();
            $table->string('event_class');
            $table->json('before_event_properties');
            $table->json('after_event_properties')->nullable();
            $table->string('backup_table', 64);
            $table->timestamp('applied_at');
            $table->unique(['correction_key', 'stored_event_id'], 'stored_event_corrections_key_event_unique');
            $table->index('stored_event_id', 'stored_event_corrections_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_event_corrections');
    }
};
