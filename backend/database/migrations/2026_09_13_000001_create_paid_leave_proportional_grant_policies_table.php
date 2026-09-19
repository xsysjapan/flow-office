<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 比例付与(労働基準法第39条第3項、労働基準法施行規則第24条の3・別表第1)の
     * 法定付与日数マスタ。週所定労働日数区分(`weekly_scheduled_days_category`、
     * 例: '4' '3' '2' '1')×`continuous_service_months`(継続勤務月数)ごとに
     * `grant_days`(付与日数)を持つ。`version`で世代管理する(spec.md 論点4)。
     */
    public function up(): void
    {
        Schema::create('paid_leave_proportional_grant_policies', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->string('weekly_scheduled_days_category');
            $table->unsignedInteger('continuous_service_months');
            $table->decimal('grant_days', 4, 1);
            $table->date('effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['version', 'weekly_scheduled_days_category', 'continuous_service_months'], 'plgpp_version_category_months_unique');
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_proportional_grant_policies');
    }
};
