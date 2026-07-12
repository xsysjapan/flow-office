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
        Schema::table('employee_shift_assignments', function (Blueprint $table) {
            $table->foreignId('shift_pattern_id')->nullable()->after('work_style_id')->constrained();
            // カレンダー基準の一括生成(UC-C003)は従来通り即時有効とし、既定値はtrueにする。
            // 3交代制シフト表(UC-C004)からのシフトパターン割当のみ、公開までは下書き扱い(false)にする。
            $table->boolean('is_published')->default(true)->after('planned_break_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_shift_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_pattern_id');
            $table->dropColumn('is_published');
        });
    }
};
