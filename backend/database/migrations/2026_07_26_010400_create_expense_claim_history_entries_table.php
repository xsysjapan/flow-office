<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 経費精算の履歴表示用Projection。workflow_request_history_entriesと同じ考え方で、
 * stored_events(EventStore)の生イベントをUIに直接公開しないための専用テーブル
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claim_history_entries', function (Blueprint $table) {
            $table->id();
            // stored_events.id。Projectorの冪等性をこのユニーク制約で担保する。
            $table->unsignedBigInteger('stored_event_id')->unique();
            $table->uuid('expense_claim_id');
            $table->string('action');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->text('comment')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['expense_claim_id', 'occurred_at'], 'expense_claim_history_entries_claim_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_history_entries');
    }
};
