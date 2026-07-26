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
        Schema::table('attendance_months', function (Blueprint $table) {
            // UC-A010: 差戻し理由。申請者側の月次勤怠画面に表示するため、通知本文だけでなく
            // Projectionにも保持する。再提出時にnullへ戻す(AttendanceMonthProjector参照)。
            $table->text('return_comment')->nullable()->after('returned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_months', function (Blueprint $table) {
            $table->dropColumn('return_comment');
        });
    }
};
