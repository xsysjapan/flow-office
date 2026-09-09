<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 最終Phase(データ移行、docs/changesets/20260906-paid-leave-domain-redesign/spec.md
 * 「既存データ移行」参照)向け。`PaidLeaveGrantCreated.source`(常設フィールド、既定'manual')は
 * これまでDBへ非正規化されていなかったため、`source`列を追加して通常Grantと
 * migration Grantを監査上区別できるようにする。`original_granted_days`/`cutover_metadata`は
 * migrateGrants()経由のGrantにのみ設定される付随情報(表示・監査専用、
 * 不変条件には使わない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('grant_reason');
            $table->decimal('original_granted_days', 5, 1)->nullable()->after('source');
            $table->json('cutover_metadata')->nullable()->after('original_granted_days');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->dropColumn(['source', 'original_granted_days', 'cutover_metadata']);
        });
    }
};
