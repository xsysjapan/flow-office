<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PaidLeaveAccountAggregate(Phase 3)向け。allocated_daysはpaid_leave_usage_allocationsの
 * 集計値を非正規化キャッシュとして持つ列で、Source of Truthではない(あくまで表示用)。
 * remaining_daysは既存列を引き続きキャッシュとして使う(旧ドメインのProjectorは
 * used_days/remaining_daysのみ更新するため、新ドメインのProjectorはallocated_days・
 * remaining_daysの両方を自身の計算で上書きする)。
 * docs/changesets/20260906-paid-leave-domain-redesign/spec.md「仕様確定事項(まとめ)」
 * 「Projection変更」節参照。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->decimal('allocated_days', 4, 1)->default(0)->after('granted_days');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->dropColumn('allocated_days');
        });
    }
};
