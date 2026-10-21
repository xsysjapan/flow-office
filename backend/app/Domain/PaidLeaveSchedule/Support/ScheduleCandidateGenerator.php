<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveGrantRuleStep;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use App\Support\BusinessDate;
use App\Support\FiscalYearCalculator;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phase C: `paid-leave:roll-schedules`・Reactorから呼ばれる、対象社員の
 * Scheduleエントリ候補(scheduledOn・区分・付与候補日数)をステートレスに算出するサービス。
 *
 * Phase Aの設計方針(EnsureFutureScheduleGenerated/RecalculateFutureScheduleは
 * 算出済みの候補値を受け取るのみで、自らProjection/Eloquentへアクセスしない)を受けて、
 * 実際の算出(hire_dateからの記念日計算・区分判定・`resolveGrantDays`優先順位)は
 * このクラスがCommandHandler(artisanコマンド)側で行う。
 *
 * `resolveGrantDays`優先順位(spec.md 論点5/16): 対象社員の`work_style_id`に一致する
 * `paid_leave_grant_rules`が`is_active=true`で存在すればそちらのsteps定義を優先し、
 * 無ければ`GrantCategoryClassifier`の区分に対応する法定Policyを適用する。
 *
 * 判定に使う`WorkStyle`は「現在時点で有効な`user_work_style_monthly_assignments`」を
 * 採用する(将来の月ごとの働き方切替までは追跡しない。切替があった場合は
 * `WorkStyleUpdated`/`UserWorkStyleAssignedForMonth`をトリガーにReactorが
 * `RecalculateFutureSchedule`で再計算する運用を前提にする)。
 *
 * docs/changesets/20260913-paid-leave-fiscal-grant-and-nav/spec.md からの移植
 * (20260914-port-to-pr112):
 * - `work_style_id`に一致するルールが無ければ、`work_style_id`がnullの全社共通ルールを
 *   フォールバックとして採用する(従来はここが未実装で、全社共通ルールが常に無視されて
 *   いた)。work_style固有ルール・全社共通ルールのいずれも無い場合、その社員については
 *   Schedule候補を一切生成しない(呼び出し側=`RollPaidLeaveSchedulesCommand`が
 *   何もdispatchしない)。
 * - `grant_cycle_type === 'mass_grant_month'`のルールは、hire_date起算の周年ではなく
 *   年度一斉付与月への前倒しアルゴリズムで初回・以降の付与日を算出する。
 * - `usage_start_date`より前の候補日は生成しない(黙ってスキップする)。
 *
 * docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md:
 * - 一斉付与日は、既定の会社カレンダーの年度開始日(`FiscalYearStartResolver`)に連動する(ルールに月は持たない)。
 * - ルールの付与日数の表は区分(`grant_category`)ごと。社員の区分(通常/比例の週日数)から引く。
 * - 付与済みの付与予定がある社員は、最後の付与済みの行を起点に次の年度開始日から並べる。
 */
class ScheduleCandidateGenerator
{
    private readonly PaidLeaveGrantRuleResolver $ruleResolver;

    private readonly FiscalYearStartResolver $fiscalYearStartResolver;

    public function __construct(
        private readonly GrantCategoryClassifier $classifier,
        ?PaidLeaveGrantRuleResolver $ruleResolver = null,
        ?FiscalYearStartResolver $fiscalYearStartResolver = null,
    ) {
        $this->ruleResolver = $ruleResolver ?? new PaidLeaveGrantRuleResolver;
        $this->fiscalYearStartResolver = $fiscalYearStartResolver ?? new FiscalYearStartResolver;
    }

    /**
     * @return array<int, array{entryId: string, scheduledOn: string, category: string, candidateGrantDays: float, isDeterminate: bool, nominalServiceMonths: int}>
     */
    public function candidatesFor(User $user, Carbon $from, Carbon $to): array
    {
        if ($user->hire_date === null) {
            return [];
        }

        // 有給自動付与が無効化されている社員はSchedule候補を生成しない
        // (`RollPaidLeaveSchedulesCommand`・各Reactorの双方から呼ばれるため、
        // 呼び出し側ではなくここで一元的にガードする)。
        if (! $user->paid_leave_auto_grant_enabled) {
            return [];
        }

        $workStyle = $this->currentWorkStyleFor($user);
        $rule = $this->ruleResolver->resolveFor($workStyle);

        // spec.md 移植対象Feature2: 対象社員にマッチする有効な付与ルールが
        // (work_style固有・全社共通のいずれも)存在しない場合、Schedule候補を
        // 一切生成しない。
        if ($rule === null) {
            return [];
        }

        $category = $workStyle !== null
            ? $this->classifier->classify($workStyle)
            : GrantCategoryClassifier::CATEGORY_NEEDS_REVIEW;

        $scheduledDates = $rule->grant_cycle_type === PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH
            ? $this->massGrantScheduledDates($user, $rule, $to)
            : $this->anniversaryScheduledDates($user->hire_date, $rule, $to);

        $candidates = [];

        foreach ($scheduledDates as ['scheduledOn' => $scheduledOn, 'nominalMonths' => $nominalMonths]) {
            if ($scheduledOn->lt($from)) {
                continue;
            }

            // spec.md 移植対象Feature4: usage_start_dateより前の候補日は生成しない
            // (黙ってスキップする)。呼び出し元(RollPaidLeaveSchedulesCommand)は
            // 既にusage_start_dateがnullでないユーザーに絞り込む想定だが、他の経路から
            // 呼ばれる可能性に備えて防御的にnullチェックする。
            if ($user->usage_start_date !== null && $scheduledOn->lt($user->usage_start_date)) {
                continue;
            }

            $result = $this->resolveGrantDays($rule, $category, $workStyle, $nominalMonths);

            $candidates[] = [
                'entryId' => (string) Str::uuid(),
                'scheduledOn' => $scheduledOn->toDateString(),
                'category' => $category,
                'candidateGrantDays' => $result->days,
                'isDeterminate' => $result->isDeterminate,
                'nominalServiceMonths' => $nominalMonths,
            ];
        }

        return $candidates;
    }

    /**
     * `nominalMonths`は継続勤務月数の法定tier判定(`resolveGrantDays`のstep/Policy
     * 参照キー)に使う「名目上の」継続勤務月数。周年方式は`scheduledOn`がちょうど
     * `hire_date + nominalMonths`なので、実際の暦月数とnominalMonthsは常に一致する。
     *
     * @return array<int, array{scheduledOn: Carbon, nominalMonths: int}>
     */
    private function anniversaryScheduledDates(Carbon $hireDate, PaidLeaveGrantRule $rule, Carbon $to): array
    {
        $firstGrantAfterMonths = $rule->first_grant_after_months ?? 6;
        $grantCycleMonths = max(1, $rule->grant_cycle_months ?? 12);

        $dates = [];
        $months = $firstGrantAfterMonths;

        // 安全弁: hire_dateが極端に古い/未来指定でも無限ループしないよう上限を設ける。
        for ($i = 0; $i < 600; $i++, $months += $grantCycleMonths) {
            $scheduledOn = $hireDate->copy()->addMonthsNoOverflow($months);

            if ($scheduledOn->gt($to)) {
                break;
            }

            $dates[] = ['scheduledOn' => $scheduledOn, 'nominalMonths' => $months];
        }

        return $dates;
    }

    /**
     * 年度はじめ一斉付与の日付(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点3・10)。
     * 年度開始日は既定の会社カレンダーの年度開始月日(`FiscalYearStartResolver`)。
     *
     * 付与済みの付与予定が無い社員:
     * 1. `sixMonthMark` = 入社日 + first_grant_after_months(`addMonthsNoOverflow`)
     * 2. `firstFiscalStart` = 入社日より後で最初の年度開始日
     * 3. 初回付与日は両者の早い方(前倒しは可、遅らせない)。名目の継続勤務月数は`first_grant_after_months`。
     * 4. `firstFiscalStart`が`sixMonthMark`より前(前倒し。同じ日は前倒しではない)なら、同じ日に名目`first+12`の
     *    2件目もまとめて作る。
     * 5. 以後は、直前の付与日より後で最初の年度開始日ごと。名目は+12か月ずつ。
     *
     * 付与済みの付与予定がある社員は、最後の付与済みの付与予定日(同じ日に2件なら名目の大きい方)を起点に、
     * 起点より後で最初の年度開始日から、名目+12か月ずつ並べる(年度開始日を変えたときに、付与済みの日付と
     * 二重付与・付与漏れの日付を作らない)。
     *
     * @return array<int, array{scheduledOn: Carbon, nominalMonths: int}>
     */
    private function massGrantScheduledDates(User $user, PaidLeaveGrantRule $rule, Carbon $to): array
    {
        $fiscalStart = $this->fiscalYearStartResolver->resolve();
        $month = $fiscalStart['month'];
        $day = $fiscalStart['day'];
        $hireDate = $user->hire_date->copy()->startOfDay();

        $firstGrantAfterMonths = $rule->first_grant_after_months ?? 6;

        $dates = [];
        $lastGranted = $this->lastGrantedEntryFor($user, $firstGrantAfterMonths);

        if ($lastGranted !== null) {
            $current = $lastGranted['scheduledOn'];
            $nominalMonths = $lastGranted['nominalMonths'];
        } else {
            $sixMonthMark = $hireDate->copy()->addMonthsNoOverflow($firstGrantAfterMonths);
            $firstFiscalStart = FiscalYearCalculator::nextStartAfter($hireDate, $month, $day);
            $current = $firstFiscalStart->lt($sixMonthMark) ? $firstFiscalStart : $sixMonthMark;
            $nominalMonths = $firstGrantAfterMonths;

            $dates[] = ['scheduledOn' => $current->copy(), 'nominalMonths' => $nominalMonths];

            if ($firstFiscalStart->lt($sixMonthMark)) {
                $nominalMonths += 12;
                $dates[] = ['scheduledOn' => $current->copy(), 'nominalMonths' => $nominalMonths];
            }
        }

        // 安全弁: 無限ループ防止。
        for ($i = 0; $i < 600; $i++) {
            $current = FiscalYearCalculator::nextStartAfter($current, $month, $day);
            $nominalMonths += 12;

            if ($current->gt($to)) {
                break;
            }

            $dates[] = ['scheduledOn' => $current->copy(), 'nominalMonths' => $nominalMonths];
        }

        // 最初の1〜2件が`$to`より後でも、上のループは2件目以降だけを見るため、ここで取り除く。
        return array_values(array_filter($dates, fn (array $date) => $date['scheduledOn']->lte($to)));
    }

    /**
     * 最後の付与済みの付与予定(付与予定日が最も遅い行。同じ日に複数あれば名目の月数が大きい方)。
     * 付与予定の文脈のProjection(`paid_leave_schedule_entries`)から読む。
     *
     * @return array{scheduledOn: Carbon, nominalMonths: int}|null
     */
    private function lastGrantedEntryFor(User $user, int $firstGrantAfterMonths): ?array
    {
        $entry = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->where('status', PaidLeaveScheduleEntry::STATUS_GRANTED)
            ->orderByDesc('scheduled_on')
            ->orderByDesc('nominal_service_months')
            ->first();

        if ($entry === null) {
            return null;
        }

        $scheduledOn = Carbon::parse($entry->scheduled_on)->startOfDay();

        if ($entry->nominal_service_months !== null) {
            $nominalMonths = (int) $entry->nominal_service_months;
        } else {
            // 古いイベント由来で名目の月数が無い行。入社日からの経過月数に最も近い「初回+12か月ずつ」の値で推定する。
            $elapsedMonths = (int) $user->hire_date->copy()->startOfDay()->diffInMonths($scheduledOn);
            $nominalMonths = $firstGrantAfterMonths + 12 * max(0, (int) round(($elapsedMonths - $firstGrantAfterMonths) / 12));
        }

        return ['scheduledOn' => $scheduledOn, 'nominalMonths' => $nominalMonths];
    }

    public function currentWorkStyleFor(User $user): ?WorkStyle
    {
        $currentYearMonth = BusinessDate::today()->format('Y-m');

        $assignment = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $user->id)
            ->where('year_month', '<=', $currentYearMonth)
            ->orderByDesc('year_month')
            ->first();

        return $assignment?->workStyle;
    }

    /**
     * 「今この勤務形態の社員」のuser_id一覧。`currentWorkStyleFor`と同じ定義で、社員ごとに
     * `year_month <= $today`の最大の月の割当を取り、その`work_style_id`が一致する社員を返す
     * (当月より後にだけ割当がある社員は含まない)。勤務形態の更新による再計算の対象の取り出しに使う。
     *
     * @return array<int, string>
     */
    public function userIdsCurrentlyOn(string $workStyleId, CarbonInterface $today): array
    {
        $currentYearMonth = $today->format('Y-m');

        $latestMonthByUser = UserWorkStyleMonthlyAssignment::query()
            ->where('year_month', '<=', $currentYearMonth)
            ->selectRaw('user_id, MAX(year_month) as latest_year_month')
            ->groupBy('user_id');

        return UserWorkStyleMonthlyAssignment::query()
            ->joinSub($latestMonthByUser, 'latest', function ($join) {
                $join->on('latest.user_id', '=', 'user_work_style_monthly_assignments.user_id')
                    ->on('latest.latest_year_month', '=', 'user_work_style_monthly_assignments.year_month');
            })
            ->where('user_work_style_monthly_assignments.work_style_id', $workStyleId)
            ->pluck('user_work_style_monthly_assignments.user_id')
            ->all();
    }

    /**
     * 付与日数。ルールの付与日数の表から、社員の区分(通常/比例の週日数)の表を引く。
     * 区分は`grant_category`(normal / proportional_N)。シフト勤務は通常の表。区分を決められない
     * (週0日・年間48日未満・所定日数が未入力・勤務形態なし)、該当する区分の表が無い、継続勤務月数に合う段が無い
     * ときは、法定へ自動で切り替えず確定不可(要確認)にする。
     */
    private function resolveGrantDays(PaidLeaveGrantRule $rule, string $category, ?WorkStyle $workStyle, int $continuousServiceMonths): GrantDaysResult
    {
        $grantCategory = $this->grantCategoryFor($category, $workStyle);

        if ($grantCategory === null) {
            return GrantDaysResult::indeterminate();
        }

        $step = $rule->steps
            ->filter(fn ($step) => $step->grant_category === $grantCategory && $step->continuous_service_months <= $continuousServiceMonths)
            ->sortByDesc('continuous_service_months')
            ->first();

        if ($step === null) {
            return GrantDaysResult::indeterminate();
        }

        return GrantDaysResult::determinate((float) $step->grant_days);
    }

    /**
     * 社員の区分から、付与日数の表の区分(`paid_leave_grant_rule_steps.grant_category`)を決める。
     */
    private function grantCategoryFor(string $category, ?WorkStyle $workStyle): ?string
    {
        return match ($category) {
            GrantCategoryClassifier::CATEGORY_NORMAL, GrantCategoryClassifier::CATEGORY_SHIFT => PaidLeaveGrantRuleStep::CATEGORY_NORMAL,
            GrantCategoryClassifier::CATEGORY_PROPORTIONAL => $workStyle !== null ? $this->classifier->proportionalGrantCategoryFor($workStyle) : null,
            default => null,
        };
    }
}
