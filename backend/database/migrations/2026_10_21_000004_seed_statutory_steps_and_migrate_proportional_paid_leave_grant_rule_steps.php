<?php

use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 付与日数の表の区分(`grant_category`)の導入に伴う、マスタのデータの移行
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点2・8)。
 *
 * 1. 勤務形態が比例に分類されるルールは、既存の表(`normal`)を`proportional_{N}`へ移す(移行の後に要確認へ
 *    後退させない)。区分の決め方は`GrantCategoryClassifier::proportionalGrantCategoryFor`。
 * 2. 付与日数の表が空の全社ルール(`work_style_id`がnull)に、法定の写し(通常・週4〜1日)を入れる。
 *    現在有効な法定マスタの版から写し、マスタが無ければシーダ(`PaidLeaveGrantPolicySeeder`)と同じ値を使う。
 * 3. 初期の全社ルールの名前を「全社共通(年度はじめ一斉付与)」にする。
 *
 * マスタの変更だけを行い、Jobは発行しない(付与予定の作り直しはデプロイ後の
 * `paid-leave:schedule:reapply-policy`)。再実行しても結果は変わらない。
 */
return new class extends Migration
{
    private const OLD_NAME = '全社共通(4月一斉付与)';

    private const NEW_NAME = '全社共通(年度はじめ一斉付与)';

    /** シーダ(`PaidLeaveGrantPolicySeeder`)と同じ値 */
    private const SEEDER_NORMAL = [6 => 10, 18 => 11, 30 => 12, 42 => 14, 54 => 16, 66 => 18, 78 => 20];

    private const SEEDER_PROPORTIONAL = [
        '4' => [6 => 7, 18 => 8, 30 => 9, 42 => 10, 54 => 12, 66 => 13, 78 => 15],
        '3' => [6 => 5, 18 => 6, 30 => 6, 42 => 8, 54 => 9, 66 => 10, 78 => 11],
        '2' => [6 => 3, 18 => 4, 30 => 4, 42 => 5, 54 => 6, 66 => 6, 78 => 7],
        '1' => [6 => 1, 18 => 2, 30 => 2, 42 => 2, 54 => 3, 66 => 3, 78 => 3],
    ];

    public function up(): void
    {
        $this->migrateProportionalRuleSteps();
        $this->copyStatutoryTablesToEmptyGlobalRules();

        DB::table('paid_leave_grant_rules')
            ->where('name', self::OLD_NAME)
            ->update(['name' => self::NEW_NAME, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // 名前以外は戻さない(区分ごとの表・法定の写しは、戻すと管理者の設定と区別できないため残す)。
        DB::table('paid_leave_grant_rules')
            ->where('name', self::NEW_NAME)
            ->update(['name' => self::OLD_NAME, 'updated_at' => now()]);
    }

    private function migrateProportionalRuleSteps(): void
    {
        $classifier = new GrantCategoryClassifier;

        $rules = DB::table('paid_leave_grant_rules')->whereNotNull('work_style_id')->get(['id', 'work_style_id']);

        foreach ($rules as $rule) {
            $workStyle = DB::table('work_styles')->where('id', $rule->work_style_id)->first();

            if ($workStyle === null || $classifier->classify($workStyle) !== GrantCategoryClassifier::CATEGORY_PROPORTIONAL) {
                continue;
            }

            $category = $classifier->proportionalGrantCategoryFor($workStyle);

            if ($category === null) {
                continue;
            }

            DB::table('paid_leave_grant_rule_steps')
                ->where('rule_id', $rule->id)
                ->where('grant_category', 'normal')
                ->update(['grant_category' => $category]);
        }
    }

    private function copyStatutoryTablesToEmptyGlobalRules(): void
    {
        $emptyGlobalRuleIds = DB::table('paid_leave_grant_rules')
            ->whereNull('work_style_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('paid_leave_grant_rule_steps')
                    ->whereColumn('paid_leave_grant_rule_steps.rule_id', 'paid_leave_grant_rules.id');
            })
            ->pluck('id');

        if ($emptyGlobalRuleIds->isEmpty()) {
            return;
        }

        $tables = ['normal' => $this->normalTable()];
        foreach ($this->proportionalTable() as $weekly => $rows) {
            $tables['proportional_'.$weekly] = $rows;
        }

        $now = now();
        $insert = [];

        foreach ($emptyGlobalRuleIds as $ruleId) {
            foreach ($tables as $category => $rows) {
                foreach ($rows as $months => $days) {
                    $insert[] = [
                        'rule_id' => $ruleId,
                        'grant_category' => $category,
                        'continuous_service_months' => $months,
                        'grant_days' => $days,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::table('paid_leave_grant_rule_steps')->insert($insert);
    }

    /**
     * 現在有効な法定の通常付与表(継続勤務月数 => 日数)。マスタが無ければシーダと同じ値。
     *
     * @return array<int, int|float>
     */
    private function normalTable(): array
    {
        $version = DB::table('paid_leave_grant_policies')
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('version');

        if ($version === null) {
            return self::SEEDER_NORMAL;
        }

        return DB::table('paid_leave_grant_policies')
            ->where('version', $version)
            ->where('is_active', true)
            ->orderBy('continuous_service_months')
            ->pluck('grant_days', 'continuous_service_months')
            ->map(fn ($days) => $days + 0)
            ->all();
    }

    /**
     * 現在有効な法定の比例付与表(週の区分 => 継続勤務月数 => 日数)。マスタが無ければシーダと同じ値。
     *
     * @return array<string, array<int, int|float>>
     */
    private function proportionalTable(): array
    {
        $version = DB::table('paid_leave_proportional_grant_policies')
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('version');

        if ($version === null) {
            return self::SEEDER_PROPORTIONAL;
        }

        $table = [];
        $rows = DB::table('paid_leave_proportional_grant_policies')
            ->where('version', $version)
            ->where('is_active', true)
            ->orderBy('continuous_service_months')
            ->get(['weekly_scheduled_days_category', 'continuous_service_months', 'grant_days']);

        foreach ($rows as $row) {
            $table[(string) $row->weekly_scheduled_days_category][(int) $row->continuous_service_months] = $row->grant_days + 0;
        }

        return $table;
    }
};
