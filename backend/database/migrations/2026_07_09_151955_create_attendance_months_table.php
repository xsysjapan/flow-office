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
        Schema::create('attendance_months', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成できるUUIDにする(AttendanceMonthProjector経由で行えるようにするため。
            // docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->string('year_month', 7); // YYYY-MM
            $table->string('status')->default('not_submitted'); // not_submitted, submitted, approved, returned, closed
            $table->foreignUuid('approver_user_id')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('snapshot_json')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'year_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_months');
    }
};
