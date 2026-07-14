<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 区分ごとの時間(所定内労働・残業・深夜・休日労働)を、日次登録後に手動で補正できるように
 * する(attendance.daily_calculation_adjustedイベント)。補正後に日次実績が再編集されて
 * 再計算(attendance.day_calculated)されると、この補正は解除される。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->boolean('is_manually_adjusted')->default(false);
            $table->foreignId('adjusted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('adjusted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_calculations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusted_by_user_id');
            $table->dropColumn(['is_manually_adjusted', 'adjusted_at']);
        });
    }
};
