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
        Schema::create('paid_leave_grants', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする。行の新規作成自体もPaidLeaveGrantProjector
            // 経由で行えるようにするため(docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->date('granted_on');
            $table->date('expires_on');
            $table->decimal('granted_days', 4, 1);
            $table->decimal('used_days', 4, 1)->default(0);
            $table->decimal('remaining_days', 4, 1);
            $table->string('grant_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grants');
    }
};
