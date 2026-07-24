<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 交代制勤務のローテーションパターン(指示書 8.4節)。A勤・B勤・C勤・休のような
 * 繰り返し周期を1レコードにまとめて保持する(A勤・B勤・C勤を別々の働き方として作らない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rotation_patterns', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成できるUUIDにする(RotationPatternProjector経由で行えるようにするため。
            // docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignUuid('work_style_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('cycle_length');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotation_patterns');
    }
};
