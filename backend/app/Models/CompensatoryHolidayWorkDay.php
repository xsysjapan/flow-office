<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 代休口座が見る休日出勤のビュー(利用者×勤務日の日区分と実労働分)。勤怠日の計算イベント
 * (attendance_day.calculated・daily_calculation_adjusted・deleted)から代休口座のProjectorだけが作る派生データ。
 * 手動付与の休日出勤の判定に使う(勤怠日テーブルは読まない)。
 */
#[Fillable(['user_id', 'work_date', 'is_holiday_day', 'work_minutes'])]
class CompensatoryHolidayWorkDay extends Model
{
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'is_holiday_day' => 'boolean',
            'work_minutes' => 'integer',
        ];
    }
}
