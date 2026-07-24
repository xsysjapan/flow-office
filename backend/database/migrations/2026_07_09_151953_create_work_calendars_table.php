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
        Schema::create('work_calendars', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成できるUUIDにする(WorkCalendarProjector経由で行えるようにするため。
            // docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('week_starts_on')->default(1); // ISO: 1=月曜
            $table->string('status')->default('draft'); // draft, published
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_calendars');
    }
};
