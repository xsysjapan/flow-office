<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 個人宛て通知の一覧・既読管理用Projection (docs/13-usecases-notification.md UC-N001)。
 * `stored_events`の`notification.queued` / `.sent` / `.confirmed`から再生成できる。
 * 主キーはコマンド側生成のUUID(`SendNotificationJob::enqueue()`が発番)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('summary');
            $table->string('detail_url')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
