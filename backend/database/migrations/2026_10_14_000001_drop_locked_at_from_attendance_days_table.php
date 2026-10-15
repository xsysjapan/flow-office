<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * attendance_days.locked_at を廃止する。日次の編集可否は月次の状態(attendance_months.status)と
 * ロック表(attendance_locks)で AttendanceEditGuard が判定する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable();
        });
    }
};
