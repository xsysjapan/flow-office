<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // spatie/laravel-event-sourcing 導入(docs/28-event-sourcing-framework-migration.md)に伴い、
        // 新しい stored_events テーブルは同パッケージのマイグレーションが作成する。旧実装
        // (App\Domain\EventSourcing\EventStore)が書いていたこのテーブルは legacy_stored_events に
        // 改名し、未移行ドメインは引き続きこちらに追記する。全ドメイン移行後にバックフィルする。
        Schema::create('legacy_stored_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->unsignedInteger('version');
            $table->string('event_type');
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['aggregate_type', 'aggregate_id', 'version']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_stored_events');
    }
};
