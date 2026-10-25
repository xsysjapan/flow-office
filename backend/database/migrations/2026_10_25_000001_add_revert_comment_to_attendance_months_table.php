<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_months', function (Blueprint $table) {
            // 確定取消(未提出に戻した)の理由。社員の月次勤怠画面に表示するため、イベントの
            // reasonをProjectorが写す。再提出時にnullへ戻す(AttendanceMonthProjector参照)。
            $table->text('revert_comment')->nullable()->after('return_comment');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_months', function (Blueprint $table) {
            $table->dropColumn('revert_comment');
        });
    }
};
