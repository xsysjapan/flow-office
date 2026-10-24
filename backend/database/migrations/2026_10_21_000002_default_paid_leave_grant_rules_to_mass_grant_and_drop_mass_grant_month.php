<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 有給付与ルールの付与方式の初期値を「年度はじめに一斉付与」(`mass_grant_month`)にし、使わなくなった
 * `mass_grant_month`列(一斉付与の月)を削除する。一斉付与日は、既定の会社カレンダーの年度開始日に連動する
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点1・3)。
 *
 * 既定のカレンダーの年度開始月と違う`mass_grant_month`を持つルールは、付与日が変わるので、削除の前に
 * ルール名と月を出力(ログとコンソール)に出す。付与予定の作り直しは、デプロイ後に運用コマンド
 * `paid-leave:schedule:reapply-policy`で行う(ここではJobを発行しない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reportRulesWithDifferentMonth();

        Schema::table('paid_leave_grant_rules', function (Blueprint $table) {
            $table->string('grant_cycle_type')->default('mass_grant_month')->change();
        });

        Schema::table('paid_leave_grant_rules', function (Blueprint $table) {
            $table->dropColumn('mass_grant_month');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_grant_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('mass_grant_month')->nullable()->after('grant_cycle_type');
        });

        Schema::table('paid_leave_grant_rules', function (Blueprint $table) {
            $table->string('grant_cycle_type')->default('anniversary')->change();
        });
    }

    private function reportRulesWithDifferentMonth(): void
    {
        if (! Schema::hasColumn('paid_leave_grant_rules', 'mass_grant_month')) {
            return;
        }

        $fiscalStartMonth = (int) (DB::table('company_calendars')->where('is_default', true)->value('fiscal_year_start_month') ?? 4);

        $rules = DB::table('paid_leave_grant_rules')
            ->where('grant_cycle_type', 'mass_grant_month')
            ->whereNotNull('mass_grant_month')
            ->where('mass_grant_month', '!=', $fiscalStartMonth)
            ->orderBy('id')
            ->get(['id', 'name', 'mass_grant_month']);

        foreach ($rules as $rule) {
            $message = "有給付与ルール「{$rule->name}」(id={$rule->id})の一斉付与月は{$rule->mass_grant_month}月ですが、既定の会社カレンダーの年度開始月は{$fiscalStartMonth}月です。"
                .'一斉付与日は年度開始日に変わります(付与日が変わります)。';

            Log::warning($message);

            if (! app()->runningUnitTests()) {
                echo $message.PHP_EOL;
            }
        }
    }
};
