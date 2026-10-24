<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 定期実行(依頼者なし)の記録を持てるように`requested_by_user_id`をnullableにし、コマンド自身が書く集計`summary`を足す
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点6)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_command_runs', function (Blueprint $table) {
            $table->foreignUuid('requested_by_user_id')->nullable()->change();
            $table->json('summary')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('admin_command_runs', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
