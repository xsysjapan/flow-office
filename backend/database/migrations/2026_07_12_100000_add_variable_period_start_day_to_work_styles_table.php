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
            // 1か月単位変形労働時間制(work_time_system=monthly_variable)の変形期間の起算日
            // (暦月の何日を起算日にするか。1なら暦月と一致)。他の労働時間制度では未使用。
            $table->unsignedTinyInteger('variable_period_start_day')->nullable()->after('deemed_daily_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn('variable_period_start_day');
        });
    }
};
