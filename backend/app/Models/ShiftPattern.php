<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * シフトパターン (docs/08-usecases-calendar-shift.md UC-C004: 3交代制シフトを作成する)。
 * 日勤・準夜勤・深夜勤・公休・明け休みなど、始業終業時刻の組み合わせをマスタ化する。
 * `prescribed_work_minutes = 0` は公休・明け休みなど非労働日のパターンを表す。
 *
 * 主キーはUUID(HasUuids)。集約ID(aggregate_id)としてstored_eventsに書き込まれるため、
 * DB採番だと確定前にProjectorが行を作成できない(docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['id', 'code', 'name', 'start_time', 'end_time', 'crosses_midnight', 'break_minutes', 'break_start_time', 'break_end_time', 'prescribed_work_minutes'])]
class ShiftPattern extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

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
