<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ローテーションからの生成元と個別上書きを区別する(指示書 8.7節)。個別のシフトパターン
 * 割当(UC-C004、AssignShiftPatternDay)を経由した日はtrueになり、ローテーションの
 * 再生成(指示書8.8節「未編集日のみ再生成」)で自動上書きされなくなる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_shift_assignments', function (Blueprint $table) {
            $table->boolean('is_manually_overridden')->default(false)->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('employee_shift_assignments', function (Blueprint $table) {
            $table->dropColumn('is_manually_overridden');
        });
    }
};
