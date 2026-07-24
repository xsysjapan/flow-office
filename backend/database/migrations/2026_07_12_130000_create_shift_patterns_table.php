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
        Schema::create('shift_patterns', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成できるUUIDにする(ShiftPatternProjector経由で行えるようにするため。
            // docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('prescribed_work_minutes')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_patterns');
    }
};
