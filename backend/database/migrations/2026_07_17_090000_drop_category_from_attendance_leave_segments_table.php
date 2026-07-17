<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 残高・承認のない「特別休暇」区分は、新設の申請・承認・消化型の特別休暇
 * (special_leave_requests等)と名称が衝突するため廃止する。以降このテーブルは
 * 遅刻・早退等を欠勤時間として記録する用途のみに使うため、区分自体が不要になる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_leave_segments', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_leave_segments', function (Blueprint $table) {
            $table->string('category')->default('absence')->after('attendance_day_id');
        });
    }
};
