<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stored_eventsの作り直し(論点18・WP10)の後に不要になった旧系統の列を撤去する。
 *
 * 作り直し後の stored_events には旧イベント・移行イベント・補正イベントが無く、休暇の入力は
 * 新しいイベント(paid_leave_request.*・special_leave.*・compensatory_leave.*・口座集約のイベント)だけになるため、
 * 次の列は参照されない。
 *
 * - paid_leave_grants: 移行監査列 original_granted_days・cutover_metadata(移行の情報はイベントの meta に残す)。
 *   source は残す(手動・定期付与・システム外からの持ち込み(carried_over)の区別に使う)。
 * - paid_leave_requests.input_source: 行を作った入力系統(旧・cutover後・新)。系統の切り替えを廃止した。
 * - paid_leave_usages / special_leave_usages / compensatory_leave_usages.stored_event_id: 旧Projectorの冪等性用の列。
 *   口座集約の消化記録は usage_id で冪等にする。
 * - attendance_day_leaves.source: 休暇ビューの系統(legacy_paid・paid_account・paid_request・special・compensatory)。
 * - leave_attendance_rate_leaves / special_leave_attendance_rate_leaves の source・usage_id: 出勤率ビューの系統と、
 *   cutover後の有給の消化記録ID→行の対応用の列。
 *
 * 索引・一意制約は列を撤去する前に外す(列指定のdropUnique/dropIndexで、SQLiteとMySQLの両方で使える形にする)。
 * 外部キーは stored_event_id・usage_id・source・input_source・移行監査列のいずれにも付いていない。
 * 索引名は既定の命名(`{table}_{column}_{unique|index}`)で、いずれも64文字以内。
 *
 * down では列を復元する。撤去した値は戻らないため、NOT NULLだった列(source)は復元時にNULL可で作る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->dropColumn(['original_granted_days', 'cutover_metadata']);
        });

        Schema::table('paid_leave_requests', function (Blueprint $table) {
            $table->dropColumn('input_source');
        });

        foreach (['paid_leave_usages', 'special_leave_usages', 'compensatory_leave_usages'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['stored_event_id']);
                $blueprint->dropColumn('stored_event_id');
            });
        }

        Schema::table('attendance_day_leaves', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        foreach (['leave_attendance_rate_leaves', 'special_leave_attendance_rate_leaves'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['usage_id']);
                $blueprint->dropColumn(['usage_id', 'source']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('leave_attendance_rate_leaves', function (Blueprint $table) {
            $table->string('source')->nullable();
            $table->uuid('usage_id')->nullable()->index();
        });
        Schema::table('special_leave_attendance_rate_leaves', function (Blueprint $table) {
            $table->string('source')->nullable();
            $table->uuid('usage_id')->nullable()->index();
        });

        Schema::table('attendance_day_leaves', function (Blueprint $table) {
            $table->string('source')->nullable();
        });

        foreach (['paid_leave_usages', 'special_leave_usages', 'compensatory_leave_usages'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('stored_event_id')->nullable()->unique();
            });
        }

        Schema::table('paid_leave_requests', function (Blueprint $table) {
            $table->string('input_source')->nullable()->after('id');
        });

        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->decimal('original_granted_days', 5, 1)->nullable()->after('source');
            $table->json('cutover_metadata')->nullable()->after('original_granted_days');
        });
    }
};
