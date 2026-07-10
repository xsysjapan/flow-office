<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-P005/UC-P006: 消滅警告・年5日取得義務警告の警告履歴(重複通知防止用)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->timestamp('expiry_warned_at')->nullable()->after('grant_reason');
            $table->timestamp('five_day_obligation_warned_at')->nullable()->after('expiry_warned_at');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->dropColumn(['expiry_warned_at', 'five_day_obligation_warned_at']);
        });
    }
};
