<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 休暇消化(paid_leave_usages/special_leave_usages/compensatory_leave_usages)に、
 * 消化が確定済み(=承認によりgrantへ反映済み)かどうかを示すis_confirmedを追加する。
 * 勤怠編集で休暇を設定した時点(承認前)でも行を作成できるよう、grant_idもnullableに
 * する(grantが決まるのは承認時のplanConsumptionのため。docs/16-database-schema.md参照)。
 * これにより、勤怠側は申請テーブルを参照しなくても、この行の存在とis_confirmedだけで
 * 「休暇が設定されているか」「確定済みか」を判定できるようになる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->boolean('is_confirmed')->default(false)->after('usage_type');
        });
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->foreignUuid('paid_leave_grant_id')->nullable()->change();
        });

        Schema::table('special_leave_usages', function (Blueprint $table) {
            $table->boolean('is_confirmed')->default(false)->after('usage_type');
        });
        Schema::table('special_leave_usages', function (Blueprint $table) {
            $table->foreignUuid('special_leave_grant_id')->nullable()->change();
        });

        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            $table->boolean('is_confirmed')->default(false)->after('usage_type');
        });
        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            $table->foreignUuid('compensatory_leave_grant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->dropColumn('is_confirmed');
        });
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->foreignUuid('paid_leave_grant_id')->nullable(false)->change();
        });

        Schema::table('special_leave_usages', function (Blueprint $table) {
            $table->dropColumn('is_confirmed');
        });
        Schema::table('special_leave_usages', function (Blueprint $table) {
            $table->foreignUuid('special_leave_grant_id')->nullable(false)->change();
        });

        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            $table->dropColumn('is_confirmed');
        });
        Schema::table('compensatory_leave_usages', function (Blueprint $table) {
            $table->foreignUuid('compensatory_leave_grant_id')->nullable(false)->change();
        });
    }
};
