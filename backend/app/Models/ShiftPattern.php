<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * シフトパターン (docs/08-usecases-calendar-shift.md UC-C004: 3交代制シフトを作成する)。
 * 日勤・準夜勤・深夜勤・公休・明け休みなど、始業終業時刻の組み合わせをマスタ化する。
 * `prescribed_work_minutes = 0` は公休・明け休みなど非労働日のパターンを表す。
 */
#[Fillable(['code', 'name', 'start_time', 'end_time', 'crosses_midnight', 'break_minutes', 'prescribed_work_minutes'])]
class ShiftPattern extends Model
{
    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
        ];
    }

    public function isWorkingPattern(): bool
    {
        return $this->prescribed_work_minutes > 0;
    }
}
