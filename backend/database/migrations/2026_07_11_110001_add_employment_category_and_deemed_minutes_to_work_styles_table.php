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
        Schema::table('work_styles', function (Blueprint $table) {
            $table->foreignId('employment_category_id')->nullable()->after('id')
                ->constrained('employment_categories')->nullOnDelete();
            $table->unsignedSmallInteger('deemed_daily_minutes')->nullable()->after('prescribed_weekly_minutes');
        });

        Schema::table('work_styles', function (Blueprint $table) {
            $table->foreignId('calendar_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropForeign(['employment_category_id']);
            $table->dropColumn(['employment_category_id', 'deemed_daily_minutes']);
        });

        Schema::table('work_styles', function (Blueprint $table) {
            $table->foreignId('calendar_id')->nullable(false)->change();
        });
    }
};
