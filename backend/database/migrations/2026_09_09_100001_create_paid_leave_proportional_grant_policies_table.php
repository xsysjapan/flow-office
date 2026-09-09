<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 比例付与の法定日数表(労働基準法39条3項、週所定労働日数区分×継続勤務年数)。
 * spec.md 論点4。`version`で版管理し、現在有効な版は「最大version」とする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_proportional_grant_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version');
            $table->unsignedTinyInteger('weekly_scheduled_days');
            $table->unsignedSmallInteger('continuous_service_months');
            $table->decimal('grant_days', 4, 1);
            $table->timestamps();

            $table->unique(['version', 'weekly_scheduled_days', 'continuous_service_months'], 'paid_leave_prop_policy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_proportional_grant_policies');
    }
};
