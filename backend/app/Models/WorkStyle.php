<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 勤務形態 (docs/08-usecases-calendar-shift.md UC-C002)。
 * 所定労働時間・残業計算の基準となるため、ここをマスタ化しハードコードしない。
 */
#[Fillable(['code', 'name', 'work_time_system', 'prescribed_daily_minutes', 'prescribed_weekly_minutes', 'default_start_time', 'default_end_time', 'default_break_minutes', 'calendar_id', 'is_shift_based', 'legal_holiday_rule', 'four_week_period_start_date'])]
class WorkStyle extends Model
{
    /** 毎週少なくとも1日の法定休日を与える(労働基準法 原則)。 */
    public const LEGAL_HOLIDAY_RULE_WEEKLY = 'weekly';

    /** 4週間を通じて4日以上の法定休日を与える変形休日制。起算日は就業規則で定める。 */
    public const LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS = 'four_weeks_four_days';

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
}
