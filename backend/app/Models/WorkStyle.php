<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 勤務形態 (docs/08-usecases-calendar-shift.md UC-C002)。
 * 所定労働時間・残業計算の基準となるため、ここをマスタ化しハードコードしない。
 */
#[Fillable(['code', 'name', 'employment_category_id', 'work_time_system', 'prescribed_daily_minutes', 'prescribed_weekly_minutes', 'deemed_daily_minutes', 'default_start_time', 'default_end_time', 'default_break_minutes', 'calendar_id', 'is_shift_based', 'legal_holiday_rule', 'four_week_period_start_date'])]
class WorkStyle extends Model
{
    /** 毎週少なくとも1日の法定休日を与える(労働基準法 原則)。 */
    public const LEGAL_HOLIDAY_RULE_WEEKLY = 'weekly';

    /** 4週間を通じて4日以上の法定休日を与える変形休日制。起算日は就業規則で定める。 */
    public const LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS = 'four_weeks_four_days';

    /** 通常勤務(固定時間制・時短勤務等)。所定労働時間との差分のみ判定が必要。 */
    public const WORK_TIME_SYSTEM_FIXED = 'fixed';

    /** 1か月単位の変形労働時間制。 */
    public const WORK_TIME_SYSTEM_MONTHLY_VARIABLE = 'monthly_variable';

    /** 裁量労働制。実労働時間ではなくdeemed_daily_minutesを給与計算上の労働時間として扱う。 */
    public const WORK_TIME_SYSTEM_DISCRETIONARY = 'discretionary';

    /** 労働基準法上の管理監督者。残業・休日の割増計算対象から除外する(深夜割増は対象)。 */
    public const WORK_TIME_SYSTEM_MANAGER_SUPERVISOR = 'manager_supervisor';

    protected function casts(): array
    {
        return [
            'is_shift_based' => 'boolean',
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
}
