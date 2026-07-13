<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ローテーションパターンを構成する各順序のシフトパターン(指示書 8.4節)。
 * sequenceは0始まり。例: [A, A, 休, B, B, 休, C, C, 休] なら sequence 0〜8。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rotation_pattern_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rotation_pattern_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('shift_pattern_id')->constrained();
            $table->timestamps();

            $table->unique(['rotation_pattern_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotation_pattern_items');
    }
};
