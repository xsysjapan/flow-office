<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 前倒しで短縮された期間の「みなし出勤日数」を持たせる(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点5)。
 * `denominator_days`/`attendance_days`にはみなし日数を含めない。古い行は0。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_schedule_assessments', function (Blueprint $table) {
            $table->unsignedInteger('deemed_attendance_days')->default(0)->after('excluded_days');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_schedule_assessments', function (Blueprint $table) {
            $table->dropColumn('deemed_attendance_days');
        });
    }
};
