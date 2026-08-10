<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * paid_leave_requestsと同じ理由(期間指定の複数日申請を1回の承認操作でまとめて承認する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_leave_requests', function (Blueprint $table) {
            $table->uuid('request_group_id')->nullable()->after('id');
            $table->index(['request_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('special_leave_requests', function (Blueprint $table) {
            $table->dropIndex(['request_group_id', 'status']);
            $table->dropColumn('request_group_id');
        });
    }
};
