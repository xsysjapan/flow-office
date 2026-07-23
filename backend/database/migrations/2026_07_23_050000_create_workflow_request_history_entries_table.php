<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-W002〜UC-W005 の履歴表示用Projection。workflow_request.*イベントを
 * イベントクラス名・payload形状に依存しない安定した形(action/actor_user_id/comment)に
 * 変換して保持する。stored_events(EventStore)を画面から直接参照させないための専用テーブル
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_request_history_entries', function (Blueprint $table) {
            $table->id();
            // stored_events.id。Projectorの冪等性(同じイベントの再適用で行が重複しない)を
            // このユニーク制約で担保する。
            $table->unsignedBigInteger('stored_event_id')->unique();
            $table->uuid('workflow_request_id');
            $table->string('action');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->text('comment')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['workflow_request_id', 'occurred_at'], 'workflow_request_history_entries_request_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_request_history_entries');
    }
};
