<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤怠側の代休付与のビュー(compensatory_grant_day_views)と、その充当の表(compensatory_grant_day_view_allocations)。
 *
 * 代休口座(CompensatoryLeaveAccount)の付与・消化記録のイベントから、勤怠側のCompensatoryGrantDayViewProjectorが
 * 作る派生データ(利用者×日付で付与の日数・時間・確定状況・充当量)。月次APIの代休の警告が、代休の付与テーブルを
 * 直接読まずにこのビューを読むために置く(原則15)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensatory_grant_day_views', function (Blueprint $table) {
            $table->uuid('grant_id')->primary();
            $table->uuid('user_id')->index();
            $table->date('work_date');
            $table->decimal('granted_days', 4, 1)->default(0);
            $table->unsignedInteger('granted_minutes')->nullable();
            $table->string('status'); // draft, confirmed, cancelled
            $table->decimal('used_days', 4, 1)->default(0);
            $table->unsignedInteger('used_minutes')->nullable();

            $table->index(['user_id', 'work_date']);
        });

        Schema::create('compensatory_grant_day_view_allocations', function (Blueprint $table) {
            $table->uuid('usage_id');
            $table->uuid('grant_id');
            $table->decimal('allocated_days', 4, 1)->default(0);
            $table->unsignedInteger('allocated_minutes')->default(0);

            $table->primary(['usage_id', 'grant_id']);
            $table->index('grant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensatory_grant_day_view_allocations');
        Schema::dropIfExists('compensatory_grant_day_views');
    }
};
