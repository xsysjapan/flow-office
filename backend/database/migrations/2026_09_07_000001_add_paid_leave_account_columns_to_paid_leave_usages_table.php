<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PaidLeaveAccountAggregate(Phase 3)向け。仕様(spec.md「Projection変更」節)では
 * `paid_leave_usages.paid_leave_grant_id`単一列を廃止しAllocationは
 * `paid_leave_usage_allocations`へ移す方針だったが、旧ドメインの
 * `App\Domain\PaidLeave\Projectors\PaidLeaveUsageProjector`/`PaidLeaveGrantProjector`が
 * この列(NOT NULL)へ都度書き込んでおり、かつ`tests/Feature/PaidLeave/*`が現行運用中
 * (旧ドメインの並行稼働がPhase 3時点でまだ生きている)であるため、本マイグレーションでは
 * `paid_leave_grant_id`列を削除しない(スキーマ除去は旧ドメイン廃止=cutover完了後の
 * 別フェーズで行う)。新ドメイン用に以下のみ追加する:
 * - `usage_id`(新ドメインのUsage識別子。PaidLeaveAccountAggregateがコマンド側で発行する
 *   UUID。stored_event_idの代わりにこの列で冪等Upsertを行う)
 * - `confirmed`/`cancelled`(新ドメインのUsage状態を明示するブール列。旧ドメインの
 *   `is_confirmed`とは別に持つ―旧ドメインの行はこの2列を使わない)
 * 新ドメインの行は`attendance_day_id`/`paid_leave_grant_id`/`paid_leave_request_id`が
 * 未確定(Designated直後はGrant未割当、workflow_request_id自体がnullのケースもある)の
 * ため、これら3列をnullableへ変更する(旧ドメインは従来通り必ず値を設定するため
 * 影響はない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->uuid('usage_id')->nullable()->unique()->after('id');
            $table->boolean('confirmed')->default(false)->after('usage_type');
            $table->boolean('cancelled')->default(false)->after('confirmed');
        });

        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->uuid('paid_leave_grant_id')->nullable()->change();
            $table->uuid('attendance_day_id')->nullable()->change();
            $table->uuid('paid_leave_request_id')->nullable()->change();
            $table->string('usage_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->uuid('paid_leave_grant_id')->nullable(false)->change();
            $table->uuid('attendance_day_id')->nullable(false)->change();
            $table->uuid('paid_leave_request_id')->nullable(false)->change();
            $table->string('usage_type')->nullable(false)->change();
        });

        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->dropColumn(['usage_id', 'confirmed', 'cancelled']);
        });
    }
};
