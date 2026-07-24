<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-P004: 有給を承認する(消化)。有効期限が近い付与分(paid_leave_grants)から
 * 優先的に消し込むため、1回の承認で複数grantにまたがって消化される場合がある
 * (docs/16-database-schema.md paid_leave_usages)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_usages', function (Blueprint $table) {
            $table->id();
            // stored_events.id。PaidLeaveUsageProjectorの冪等性(同じイベントの再適用で
            // 行が重複しない)をこのユニーク制約で担保する。この行自体は
            // paid_leave_grant集約が記録するpaid_leave.usedイベントの派生データであり、
            // 自身は集約ルートではないためDB採番のままでよい。テストがイベントを経由せず
            // 直接rowを作成することがあるためnullableにする(その場合は冪等性の対象外)。
            $table->unsignedBigInteger('stored_event_id')->nullable()->unique();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('attendance_day_id')->constrained();
            $table->foreignUuid('paid_leave_grant_id')->constrained();
            $table->foreignUuid('paid_leave_request_id')->constrained();
            $table->date('used_on');
            $table->decimal('used_days', 4, 1);
            $table->unsignedSmallInteger('used_minutes')->nullable();
            $table->string('usage_type'); // full, am_half, pm_half, hourly
            $table->timestamps();

            $table->index(['user_id', 'used_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_usages');
    }
};
