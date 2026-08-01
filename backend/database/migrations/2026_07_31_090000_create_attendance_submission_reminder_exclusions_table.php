<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特定の社員×特定の年月の組み合わせを、勤怠未提出督促(WarnUnsubmittedAttendanceHandler)の
 * 対象から個別に除外するための記録。`usage_start_date`/`hire_date`による除外条件とは別の、
 * 汎用的な例外的対応の手段(誤ってその月を提出対象にしてしまった場合の是正など)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::createIfNotExists('attendance_submission_reminder_exclusions', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成できるUUIDにする(AttendanceSubmissionReminderExclusionProjector
            // 経由で行えるようにするため。docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('year_month', 7); // 'YYYY-MM'
            $table->text('reason');
            $table->foreignUuid('excluded_by_user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['user_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_submission_reminder_exclusions');
    }
};
