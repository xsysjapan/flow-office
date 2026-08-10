<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 期間指定でまとめて申請した複数日分(1日1行)を承認者が1回の承認操作でまとめて
 * 承認できるようにするため、同じ申請操作で作成された行を束ねるグループIDを持たせる
 * (単日申請の場合はnull。PaidLeaveRequest/SpecialLeaveRequestと同じ考え方)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensatory_leave_requests', function (Blueprint $table) {
            $table->uuid('request_group_id')->nullable()->after('id');
            $table->index(['request_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('compensatory_leave_requests', function (Blueprint $table) {
            $table->dropIndex(['request_group_id', 'status']);
            $table->dropColumn('request_group_id');
        });
    }
};
