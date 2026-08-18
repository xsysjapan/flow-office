<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理者が発行済みの有給付与を取り消せるようにする(未消化のGrantのみ取消可能。
 * RevokePaidLeaveGrantHandler参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->string('status')->default('active')->after('grant_reason'); // active, revoked
            $table->timestamp('revoked_at')->nullable()->after('status');
            $table->foreignUuid('revoked_by_user_id')->nullable()->after('revoked_at')->constrained('users');
            $table->string('revoke_reason')->nullable()->after('revoked_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by_user_id');
            $table->dropColumn(['status', 'revoked_at', 'revoke_reason']);
        });
    }
};
