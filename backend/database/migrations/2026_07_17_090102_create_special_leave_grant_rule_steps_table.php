<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_leave_grant_rule_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('special_leave_grant_rules')->cascadeOnDelete();
            $table->unsignedSmallInteger('continuous_service_months');
            $table->unsignedTinyInteger('grant_days');
            $table->timestamps();

            $table->unique(['rule_id', 'continuous_service_months']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_leave_grant_rule_steps');
    }
};
