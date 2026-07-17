<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇の名前付き種別マスタ(例: 誕生日休暇、慶弔休暇)。有給休暇と異なり法定の
 * 制度ではないため、会社が任意の名前・有効無効を設定できるようにする
 * (CLAUDE.md「法務判断が必要な値はマスタ化する」と同じ考え方)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_leave_types');
    }
};
