<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 付与日数の表(`paid_leave_grant_rule_steps`)を区分(`grant_category`: normal / proportional_4〜1)ごとに持たせる
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点2)。
 *
 * uniqueは(rule_id, grant_category, continuous_service_months)にする。MySQLでは`rule_id`の外部キーが
 * 古いuniqueを索引に使っているため、新しいuniqueを追加してから古いものを消す。
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'paid_leave_grant_rule_steps_rule_months_unique';

    private const NEW_UNIQUE = 'paid_leave_grant_rule_steps_rule_category_months_unique';

    public function up(): void
    {
        Schema::table('paid_leave_grant_rule_steps', function (Blueprint $table) {
            $table->string('grant_category')->default('normal')->after('rule_id');
        });

        Schema::table('paid_leave_grant_rule_steps', function (Blueprint $table) {
            $table->unique(['rule_id', 'grant_category', 'continuous_service_months'], self::NEW_UNIQUE);
        });

        Schema::table('paid_leave_grant_rule_steps', function (Blueprint $table) {
            $table->dropUnique(self::OLD_UNIQUE);
        });
    }

    public function down(): void
    {
        // 区分の無い古い表の形では、比例の区分の行は表せない。また000004のdown()は法定の写し・比例の移行を戻さないため、
        // 区分ごとに同じ継続勤務月数の行が残り、古いuniqueと重なって付け直せない。そのため先に区分が normal 以外の行を消す。
        DB::table('paid_leave_grant_rule_steps')->where('grant_category', '!=', 'normal')->delete();

        Schema::table('paid_leave_grant_rule_steps', function (Blueprint $table) {
            $table->unique(['rule_id', 'continuous_service_months'], self::OLD_UNIQUE);
        });

        Schema::table('paid_leave_grant_rule_steps', function (Blueprint $table) {
            $table->dropUnique(self::NEW_UNIQUE);
        });

        Schema::table('paid_leave_grant_rule_steps', function (Blueprint $table) {
            $table->dropColumn('grant_category');
        });
    }
};
