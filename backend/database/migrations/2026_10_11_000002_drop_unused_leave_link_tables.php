<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 書き込み・読み取りが無くなった中間テーブルを撤去する(stored_eventsの作り直し(論点18・WP10)で、消化記録ID→申請IDの
 * 対応を持つ必要がなくなったため)。
 *
 * - paid_leave_request_usage_links: 有給の消化記録ID→有給申請ID(旧・cutover後の確定・取消イベントの対応用)。
 * - attendance_day_leave_paid_usages: 有給の消化記録ID→有給申請ID(勤怠の休暇ビューの投影の補助表)。
 *
 * down では空のテーブルを元の構造で復元する(撤去した行は戻らない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('paid_leave_request_usage_links');
        Schema::dropIfExists('attendance_day_leave_paid_usages');
    }

    public function down(): void
    {
        Schema::create('paid_leave_request_usage_links', function (Blueprint $table) {
            $table->uuid('usage_id')->primary();
            $table->uuid('paid_leave_request_id')->index();
        });

        Schema::create('attendance_day_leave_paid_usages', function (Blueprint $table) {
            $table->uuid('usage_id')->primary();
            $table->uuid('leave_request_id');
        });
    }
};
