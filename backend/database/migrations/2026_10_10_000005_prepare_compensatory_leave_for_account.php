<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 代休の消化記録・付与を利用者単位の口座集約(CompensatoryLeaveAccountAggregate)の派生データとして作れるようにする
 * (仕様確定事項D・I。特別休暇の2026_10_10_000004と同じ方式)。
 *
 * - compensatory_leave_grants: 勤怠日への一意制約(attendance_day_id unique)を撤去する。付与は口座の集約から
 *   作るため勤怠日を持たない(列は旧系統の行のために残す)。
 * - compensatory_leave_usages: usage_id(口座集約が発行する消化記録ID。旧系統の行は null)、未充当量
 *   (unallocated_days・unallocated_minutes。論点17)を追加する。勤怠日・申請テーブルへの外部キーを撤去し、
 *   attendance_day_id は任意にする(新規には設定しない)。
 * - compensatory_leave_usage_allocations: 消化記録と付与の充当の表。口座Projectorだけが書き込む派生データ。
 *   付与への外部キーは付けない(リビルドの順序に依存させないため)。
 * - compensatory_holiday_work_days: 口座が休日出勤の実績を確認するためのビュー(勤怠の計算イベントから作る派生データ。
 *   手動付与の判定で勤怠日を読まないため)。
 *
 * down では撤去した制約・一意制約を復元する(データが参照整合を満たさない場合は失敗する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensatory_leave_grants', function (Blueprint $table) {
            $table->dropUnique('compensatory_leave_grants_attendance_day_id_unique');
        });

        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            $table->uuid('usage_id')->nullable()->unique();
            $table->decimal('unallocated_days', 4, 1)->default(0);
            $table->unsignedInteger('unallocated_minutes')->default(0);
        });

        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            // 列指定で撤去する(SQLiteは名前指定のdropに対応しないため。MySQLでも同じ形が使える)。
            $table->dropForeign(['attendance_day_id']);
            $table->dropForeign(['compensatory_leave_request_id']);
        });

        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            $table->uuid('attendance_day_id')->nullable()->change();
        });

        Schema::create('compensatory_leave_usage_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('usage_id')->index();
            $table->uuid('grant_id')->index();
            $table->decimal('allocated_days', 4, 1)->default(0);
            $table->unsignedInteger('allocated_minutes')->default(0);
            $table->timestamps();

            $table->unique(['usage_id', 'grant_id']);
        });

        Schema::create('compensatory_holiday_work_days', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained();
            $table->date('work_date');
            $table->boolean('is_holiday_day')->default(false);
            $table->unsignedInteger('work_minutes')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensatory_holiday_work_days');
        Schema::dropIfExists('compensatory_leave_usage_allocations');

        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            $table->dropColumn(['usage_id', 'unallocated_days', 'unallocated_minutes']);
        });

        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            $table->foreign('attendance_day_id')->references('id')->on('attendance_days');
            $table->foreign('compensatory_leave_request_id')->references('id')->on('compensatory_leave_requests');
        });

        Schema::table('compensatory_leave_grants', function (Blueprint $table) {
            $table->unique('attendance_day_id', 'compensatory_leave_grants_attendance_day_id_unique');
        });
    }
};
