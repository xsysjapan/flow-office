<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 勤務形態 (docs/08-usecases-calendar-shift.md UC-C002)。
 * 所定労働時間・残業計算の基準となるため、ここをマスタ化しハードコードしない。
 *
 * 主キーはUUID(HasUuids)。集約ID(aggregate_id)としてstored_eventsに書き込まれるため、
 * DB採番だと確定前にProjectorが行を作成できない(docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['id', 'code', 'name', 'employment_category_id', 'work_time_system', 'prescribed_daily_minutes', 'prescribed_weekly_minutes', 'deemed_daily_minutes', 'default_start_time', 'default_end_time', 'default_break_minutes', 'rounding_unit_minutes', 'default_break_start_time', 'default_break_end_time', 'auto_break_enabled', 'calendar_id', 'is_shift_based', 'is_default', 'system_generated', 'legal_holiday_rule', 'four_week_period_start_date', 'variable_period_start_day', 'max_consecutive_work_days', 'settlement_start_day', 'core_time_enabled', 'core_time_start', 'core_time_end', 'flexible_time_start', 'flexible_time_end'])]
class WorkStyle extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** 毎週少なくとも1日の法定休日を与える(労働基準法 原則)。 */
    public const LEGAL_HOLIDAY_RULE_WEEKLY = 'weekly';

    /** 4週間を通じて4日以上の法定休日を与える変形休日制。起算日は就業規則で定める。 */
    public const LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS = 'four_weeks_four_days';

    /**
     * 法定休日を決めない方式。どの日が法定休日かは事前に固定せず、週ごとに
     * LegalHolidayResolverが指定(legal_holiday_designations)または自動推定
     * (週内で休みとなっている最後の日)で解決する。
     */
    public const LEGAL_HOLIDAY_RULE_UNDETERMINED = 'undetermined';

    /** 通常勤務(固定時間制・時短勤務等)。所定労働時間との差分のみ判定が必要。 */
    public const WORK_TIME_SYSTEM_FIXED = 'fixed';

    /** 1か月単位の変形労働時間制。 */
    public const WORK_TIME_SYSTEM_MONTHLY_VARIABLE = 'monthly_variable';

    /** 裁量労働制。実労働時間ではなくdeemed_daily_minutesを給与計算上の労働時間として扱う。 */
    public const WORK_TIME_SYSTEM_DISCRETIONARY = 'discretionary';

    /** 労働基準法上の管理監督者。残業・休日の割増計算対象から除外する(深夜割増は対象)。 */
    public const WORK_TIME_SYSTEM_MANAGER_SUPERVISOR = 'manager_supervisor';

    /**
     * フレックスタイム制。日次の始業・終業時刻ではなく清算期間全体で労働時間を管理する
     * (指示書 7章)。初期実装では清算期間は月単位のみ(settlement_start_day起算)とし、
     * 清算期間の必要労働時間は prescribed_daily_minutes × 清算期間内の所定労働日数から
     * 算出する(FlexSettlementSummaryCalculator参照)。
     */
    public const WORK_TIME_SYSTEM_FLEX = 'flex';

    protected function casts(): array
    {
        return [
            'is_shift_based' => 'boolean',
            'is_default' => 'boolean',
            'system_generated' => 'boolean',
            'auto_break_enabled' => 'boolean',
            'core_time_enabled' => 'boolean',
            'four_week_period_start_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<WorkCalendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class, 'calendar_id');
    }

    /**
     * @return BelongsTo<EmploymentCategory, $this>
     */
    public function employmentCategory(): BelongsTo
    {
        return $this->belongsTo(EmploymentCategory::class, 'employment_category_id');
    }

    /**
     * 1か月単位変形労働時間制(work_time_system=monthly_variable)の、指定日が属する
     * 変形期間の開始日・終了日を返す。起算日(variable_period_start_day)を跨ぐ月の
     * 日数差はその月の末日にクランプする(例: 起算日31日で2月を跨ぐ場合は2月末日)。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function variablePeriodBoundariesFor(Carbon $date): array
    {
        return $this->monthlyPeriodBoundariesFor($date, $this->variable_period_start_day ?? 1);
    }

    /**
     * フレックスタイム制(work_time_system=flex)の、指定日が属する清算期間の開始日・
     * 終了日を返す。起算日(settlement_start_day)の扱いはvariablePeriodBoundariesForと
     * 同じ(初期実装では月単位の清算期間のみ対応。指示書 7.2節「初期実装では月単位を
     * 優先する」)。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function settlementPeriodBoundariesFor(Carbon $date): array
    {
        return $this->monthlyPeriodBoundariesFor($date, $this->settlement_start_day ?? 1);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthlyPeriodBoundariesFor(Carbon $date, int $startDay): array
    {
        $start = $date->copy()->day(min($startDay, $date->daysInMonth));
        if ($start->gt($date)) {
            $start = $start->copy()->subMonthNoOverflow();
            $start = $start->copy()->day(min($startDay, $start->daysInMonth));
        }

        $end = $start->copy()->addMonthNoOverflow()->subDay();

        return [$start->copy()->startOfDay(), $end->copy()->endOfDay()];
    }
}
