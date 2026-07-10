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
        Schema::create('paid_leave_grant_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('work_style_id')->nullable()->constrained();
            $table->unsignedTinyInteger('min_attendance_rate')->default(80);
            $table->unsignedSmallInteger('first_grant_after_months')->default(6);
            $table->unsignedSmallInteger('grant_cycle_months')->default(12);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grant_rules');
    }
};
