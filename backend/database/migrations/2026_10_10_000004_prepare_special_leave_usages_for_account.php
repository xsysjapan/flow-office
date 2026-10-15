<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇の消化記録を利用者単位の口座集約(SpecialLeaveAccountAggregate)の派生データとして作れるようにする
 * (仕様確定事項D。有給の2026_10_10_000003と同じ方式)。
 *
 * - usage_id: 口座集約が発行する消化記録ID(UUID)。旧系統の行は null のまま残す(移行時に置き換える)。
 * - unallocated_days: 承認時に充当できなかった日数(論点17。残数不足でも承認し、不足量を記録する)。
 * - 外部キーの撤去: attendance_day_id(勤怠日)・special_leave_request_id(申請テーブル)。残数文脈は勤怠日・
 *   申請テーブルを知らず、リビルドの順序にも依存させないため。attendance_day_id は任意にする(新規には設定しない)。
 * - special_leave_usage_allocations: 消化記録と付与の充当の表(1つの消化記録が複数の付与にまたがる場合は付与ごとに行)。
 *   口座Projectorだけが書き込む派生データ。
 *
 * down では外部キーを復元する(attendance_day_id・special_leave_request_idのデータが参照整合を満たさない場合は失敗する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_leave_usages', function (Blueprint $table) {
            $table->uuid('usage_id')->nullable()->unique();
            $table->decimal('unallocated_days', 4, 1)->default(0);
        });

        Schema::table('special_leave_usages', function (Blueprint $table) {
            // 列指定で撤去する(SQLiteは名前指定のdropに対応しないため。MySQLでも同じ形が使える)。
            $table->dropForeign(['attendance_day_id']);
            $table->dropForeign(['special_leave_request_id']);
        });

        Schema::table('special_leave_usages', function (Blueprint $table) {
            $table->uuid('attendance_day_id')->nullable()->change();
        });

        Schema::create('special_leave_usage_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('usage_id')->index();
            $table->foreignUuid('grant_id')->constrained('special_leave_grants');
            $table->decimal('allocated_days', 4, 1);
            $table->timestamps();

            $table->unique(['usage_id', 'grant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_leave_usage_allocations');

        Schema::table('special_leave_usages', function (Blueprint $table) {
            $table->dropColumn(['usage_id', 'unallocated_days']);
        });

        Schema::table('special_leave_usages', function (Blueprint $table) {
            $table->foreign('attendance_day_id')->references('id')->on('attendance_days');
            $table->foreign('special_leave_request_id')->references('id')->on('special_leave_requests');
        });
    }
};
