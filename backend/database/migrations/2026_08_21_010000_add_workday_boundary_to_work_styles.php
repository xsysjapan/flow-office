<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->string('workday_boundary_type', 20)->default('work_date')->after('work_time_system');
            $table->time('workday_boundary_time')->nullable()->after('workday_boundary_type');
        });
    }

    public function down(): void
    {
        Schema::table('work_styles', fn (Blueprint $table) => $table->dropColumn(['workday_boundary_type', 'workday_boundary_time']));
    }
};
