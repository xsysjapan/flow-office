<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-event-sourcing 用のEventStoreテーブル(標準スキーマ)。
 * docs/28-event-sourcing-framework-migration.md 参照。ドメイン移行が進むごとに
 * このテーブルへ書き込むドメインが増え、legacy_stored_events (旧実装)は縮小していく。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('aggregate_uuid')->nullable();
            $table->unsignedBigInteger('aggregate_version')->nullable();
            $table->unsignedTinyInteger('event_version')->default(1);
            $table->string('event_class');
            $table->json('event_properties');
            $table->json('meta_data');
            $table->timestamp('created_at');
            $table->index('event_class');
            $table->index('aggregate_uuid');

            $table->unique(['aggregate_uuid', 'aggregate_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_events');
    }
};
