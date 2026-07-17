<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇種別ごとの自動付与ルール(paid_leave_grant_rulesと同じ形)。有給と違い
 * 法定の時効(2年)がないため、`expires_after_months`をnullable(付与日から失効日までの
 * 月数。null=失効しない)として持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_leave_grant_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('special_leave_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('work_style_id')->nullable()->constrained();
            $table->unsignedTinyInteger('min_attendance_rate')->default(80);
            $table->unsignedSmallInteger('first_grant_after_months')->default(0);
            $table->unsignedSmallInteger('grant_cycle_months')->default(12);
            $table->unsignedSmallInteger('expires_after_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_leave_grant_rules');
    }
};
