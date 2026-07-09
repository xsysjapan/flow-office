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
        Schema::create('work_calendar_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('work_calendars')->cascadeOnDelete();
            $table->date('date');
            $table->string('day_type'); // weekday, legal_holiday, company_holiday, special_working_day
            $table->boolean('is_working_day')->default(true);
            $table->boolean('is_legal_holiday')->default(false);
            $table->boolean('is_company_holiday')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['calendar_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_calendar_days');
    }
};
