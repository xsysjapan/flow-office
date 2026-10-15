<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 有給申請テーブル(paid_leave_requests)を休暇申請文脈のPaidLeaveRequestProjectorが作るための変更
 * (論点4・論点13・仕様確定事項C)。
 *
 * - input_source: 申請IDの行を作った入力系統(legacy_paid/paid_account/paid_request)。申請IDごとに
 *   系統は排他で、paid_request(migrated/requestedを含む新系統)が一度でも入った行は以後、旧系統・
 *   cutover後の系統のイベントで変えない。NULLは本変更前にPaidLeaveUsageAllocationProjectorが作った行。
 * - paid_leave_request_usage_links: 有給の消化記録ID→有給申請ID(cutover後の確定・取消イベントは
 *   usageIdしか持たないため)。PaidLeaveRequestProjectorだけが書き込む派生データ。
 *
 * - 消化記録(paid_leave_usages)の外部キー撤去: paid_leave_request_id・attendance_day_id の外部キーを外す
 *   (列は残す)。仕様確定事項Dの外部キー撤去の先行実施。申請行・勤怠日は新Projectorや勤怠側の
 *   Projectorが作るため、消化記録の反映順に依存させないための撤去。
 *
 * ワークフローID→有給申請IDは休暇申請文脈の既存の対応表(leave_request_workflow_links)を使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_requests', function (Blueprint $table) {
            $table->string('input_source')->nullable()->after('id');
        });

        Schema::create('paid_leave_request_usage_links', function (Blueprint $table) {
            $table->uuid('usage_id')->primary();
            $table->uuid('paid_leave_request_id')->index();
        });

        // 外部キーは作成時の規約名(paid_leave_usages_<列>_foreign)で、テーブル名は変わっていないため
        // 列指定の形で撤去する。SQLiteは名前指定のdropに対応しないため列指定が必要で、MySQLでも同じ形が使える。
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->dropForeign(['paid_leave_request_id']);
            $table->dropForeign(['attendance_day_id']);
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->foreign('paid_leave_request_id')->references('id')->on('paid_leave_requests');
            $table->foreign('attendance_day_id')->references('id')->on('attendance_days');
        });

        Schema::dropIfExists('paid_leave_request_usage_links');

        Schema::table('paid_leave_requests', function (Blueprint $table) {
            $table->dropColumn('input_source');
        });
    }
};
