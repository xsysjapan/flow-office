<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveProportionalGrantPolicy;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
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
 */
class ScheduleCandidateGenerator
{
    public function __construct(private readonly GrantCategoryClassifier $classifier) {}

    /**
     * @return array<int, array{entryId: string, scheduledOn: string, category: string, candidateGrantDays: float, isDeterminate: bool}>
     */
    public function candidatesFor(User $user, Carbon $from, Carbon $to): array
    {
        if ($user->hire_date === null) {
            return [];
        }

        $workStyle = $this->currentWorkStyleFor($user);
        $rule = $this->matchingRuleFor($workStyle);

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
            ? $this->massGrantScheduledDates($user->hire_date, $rule, $to)
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
            ];
        }

        return $candidates;
    }

    /**
     * 対象社員の`WorkStyle`に一致する`paid_leave_grant_rules`を優先し、無ければ
     * `work_style_id`がnullの全社共通ルールをフォールバックとして採用する。
     */
    private function matchingRuleFor(?WorkStyle $workStyle): ?PaidLeaveGrantRule
    {
        if ($workStyle !== null) {
            $specific = PaidLeaveGrantRule::query()
                ->where('work_style_id', $workStyle->id)
                ->where('is_active', true)
                ->with('steps')
                ->first();

            if ($specific !== null) {
                return $specific;
            }
        }

        return PaidLeaveGrantRule::query()
            ->whereNull('work_style_id')
            ->where('is_active', true)
            ->with('steps')
            ->first();
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
            $scheduledOn = $hireDate->copy()->addMonths($months);

            if ($scheduledOn->gt($to)) {
                break;
            }

            $dates[] = ['scheduledOn' => $scheduledOn, 'nominalMonths' => $months];
        }

        return $dates;
    }

    /**
     * 年度一斉付与月への前倒しアルゴリズム(spec.md 移植対象Feature1)。
     *
     * 1. `sixMonthMark` = hire_date + first_grant_after_months
     * 2. `nextMassGrantDate` = hire_dateより後の直近の「一斉付与月の1日」
     * 3. `nextMassGrantDate` が `sixMonthMark` より前なら、初回付与日は前倒しして
     *    `nextMassGrantDate`(例: 一斉付与月=4月なら、11月〜3月入社者は6ヶ月経過を
     *    待たず4月に前倒し)。
     * 4. そうでなければ、初回付与日は `sixMonthMark`(4月〜10月入社者は6ヶ月経過時点。
     *    一斉付与月まで待つと遅すぎるため)。
     * 5. 2回目以降は、初回付与日より後の直近の「一斉付与月の1日」(初回付与日自体が
     *    既にその日である場合は翌年扱い)、以降は毎年。
     *
     * `nominalMonths`(法定tier判定用の名目継続勤務月数)は、実際の暦月数
     * (`hire_date`から`scheduledOn`までの実日数ベースの経過月数)とは別に、
     * 「これが何回目の付与か」から機械的に定める: 初回は前倒しの有無によらず
     * 常に`first_grant_after_months`(例:6)、2回目以降は前回の`nominalMonths`+12
     * (法定の1年ごとの加算)とする。前倒しで実際の経過月数が6ヶ月未満になっても、
     * `steps`/法定Policyの参照キーには本来の「初回付与」時点の月数(6)を使うことで、
     * 前倒し付与でも通常の付与日数テーブルが正しく参照される。
     *
     * @return array<int, array{scheduledOn: Carbon, nominalMonths: int}>
     */
    private function massGrantScheduledDates(Carbon $hireDate, PaidLeaveGrantRule $rule, Carbon $to): array
    {
        $massGrantMonth = $rule->mass_grant_month;

        if ($massGrantMonth === null) {
            // データ不整合(mass_grant_month未設定)。安全側に倒し、周年サイクルへ
            // フォールバックする(コントローラ側バリデーションで通常発生しない想定)。
            return $this->anniversaryScheduledDates($hireDate, $rule, $to);
        }

        $firstGrantAfterMonths = $rule->first_grant_after_months ?? 6;
        $sixMonthMark = $hireDate->copy()->addMonths($firstGrantAfterMonths);
        $nextMassGrantDate = $this->nextMassGrantMonthFirstDayAfter($hireDate, $massGrantMonth);

        $firstGrantDate = $nextMassGrantDate->lt($sixMonthMark) ? $nextMassGrantDate : $sixMonthMark;

        $dates = [];
        $current = $firstGrantDate;
        $nominalMonths = $firstGrantAfterMonths;

        // 安全弁: 無限ループ防止。
        for ($i = 0; $i < 600; $i++) {
            if ($current->gt($to)) {
                break;
            }

            $dates[] = ['scheduledOn' => $current->copy(), 'nominalMonths' => $nominalMonths];
            $current = $this->nextMassGrantMonthFirstDayAfter($current, $massGrantMonth);
            $nominalMonths += 12;
        }

        return $dates;
    }

    /**
     * `$after`より厳密に後の、直近の「$month月1日」を返す。`$after`自身が
     * ちょうどその日である場合は翌年の同日を返す(=「strictly after」)。
     */
    private function nextMassGrantMonthFirstDayAfter(Carbon $after, int $month): Carbon
    {
        $candidate = Carbon::create($after->year, $month, 1)->startOfDay();

        if ($candidate->lte($after->copy()->startOfDay())) {
            $candidate = $candidate->addYear();
        }

        return $candidate;
    }

    private function currentWorkStyleFor(User $user): ?WorkStyle
    {
        $currentYearMonth = Carbon::today()->format('Y-m');

        $assignment = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $user->id)
            ->where('year_month', '<=', $currentYearMonth)
            ->orderByDesc('year_month')
            ->first();

        return $assignment?->workStyle;
    }

    private function resolveGrantDays(?PaidLeaveGrantRule $rule, string $category, ?WorkStyle $workStyle, int $continuousServiceMonths): GrantDaysResult
    {
        if ($rule !== null) {
            $step = $rule->steps
                ->filter(fn ($step) => $step->continuous_service_months <= $continuousServiceMonths)
                ->sortByDesc('continuous_service_months')
                ->first();

            if ($step !== null) {
                return GrantDaysResult::determinate((float) $step->grant_days);
            }

            // spec.md 移植対象Feature2 (a): ルールは存在するが、対象の継続勤務月数を
            // カバーするstepが無い。法定Policyへフォールバックはせず、確定不可
            // (NeedsReview)として扱う(ルールが指定されている以上、その内容が
            // 不完全であることを可視化する)。
            return GrantDaysResult::indeterminate();
        }

        return match ($category) {
            GrantCategoryClassifier::CATEGORY_NORMAL => $this->determinateOrIndeterminate(
                PaidLeaveGrantPolicy::grantDaysFor(
                    $continuousServiceMonths,
                    PaidLeaveGrantPolicy::latestVersion() ?? 'v1',
                )
            ),
            GrantCategoryClassifier::CATEGORY_PROPORTIONAL => $this->determinateOrIndeterminate(
                PaidLeaveProportionalGrantPolicy::grantDaysFor(
                    $this->weeklyScheduledDaysCategoryFor($workStyle),
                    $continuousServiceMonths,
                    PaidLeaveProportionalGrantPolicy::latestVersion() ?? 'v1',
                )
            ),
            GrantCategoryClassifier::CATEGORY_SHIFT => $this->shiftGrantDaysEstimate($workStyle, $continuousServiceMonths),
            // NeedsReview: 候補日数は未確定。管理者確認前提(依頼書§31の精神)。
            default => GrantDaysResult::indeterminate(),
        };
    }

    /**
     * spec.md 移植対象Feature2 (b)(c): 法定Policyテーブルに該当月数の行が無い場合
     * (`grantDaysFor`がnullを返す場合)は、0.0日と確定不可を区別するためindeterminateとする。
     */
    private function determinateOrIndeterminate(?float $days): GrantDaysResult
    {
        return $days !== null ? GrantDaysResult::determinate($days) : GrantDaysResult::indeterminate();
    }

    /**
     * 比例付与区分('4'/'3'/'2'/'1')への変換。`weekly_scheduled_days`が3〜4の間の
     * 端数等は切り捨てず「以上」で判定し、範囲外は1〜4へクランプする
     * (未入力の場合はGrantCategoryClassifierが既にNeedsReview/通常判定に倒しているため
     * ここには来ない想定だが、防御的に'4'にフォールバックする)。
     */
    private function weeklyScheduledDaysCategoryFor(?WorkStyle $workStyle): string
    {
        $days = $workStyle?->weekly_scheduled_days;

        if ($days === null) {
            return PaidLeaveProportionalGrantPolicy::CATEGORY_WEEKLY_4_DAYS;
        }

        $clamped = (int) max(1, min(4, round($days)));

        return (string) $clamped;
    }

    /**
     * シフト勤務区分(依頼書§35)の候補日数。本来は「初回6ヶ月は実労働日数×2、
     * 1.5年以降は前年実労働日数」という実績ベースの算出だが、Schedule事前生成の時点では
     * 未来の実績が存在しないため、`WorkStyle.agreed_scheduled_days_per_year`
     * (合意された目安勤務日数)を週相当日数に換算し、比例付与表を目安として適用する
     * (実際の確定値は出勤率Assessment・管理者Overrideで補正する運用を前提にした
     * 暫定近似値。Phase C実装時の判断)。目安が未設定の場合は確定不可
     * (NeedsReview相当)として扱う。
     */
    private function shiftGrantDaysEstimate(?WorkStyle $workStyle, int $continuousServiceMonths): GrantDaysResult
    {
        $agreedDaysPerYear = $workStyle?->agreed_scheduled_days_per_year;

        if ($agreedDaysPerYear === null) {
            return GrantDaysResult::indeterminate();
        }

        $weeklyEquivalent = (int) max(1, min(4, round($agreedDaysPerYear / 52)));

        return $this->determinateOrIndeterminate(
            PaidLeaveProportionalGrantPolicy::grantDaysFor(
                (string) $weeklyEquivalent,
                $continuousServiceMonths,
                PaidLeaveProportionalGrantPolicy::latestVersion() ?? 'v1',
            )
        );
    }
}
