<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 打刻ログ (docs/07-usecases-attendance.md UC-A012, docs/03-architecture.md 3.3)。
 *
 * 将来的にICカード端末やモバイル端末など複数デバイスから打刻を受け付けるための生ログで、
 * 勤怠の正 (attendance_days / attendance_breaks) ではない。同一user_id・work_dateに対して
 * 重複や矛盾した打刻が記録されることを前提とし、一意制約は設けない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained();
            $table->date('work_date');
            $table->string('punch_type');
            $table->dateTime('punched_at');
            $table->string('source');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
