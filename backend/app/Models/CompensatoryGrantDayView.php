<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 勤怠側の代休付与のビュー(利用者×日付の付与の日数・時間・確定状況・充当量)。代休口座のイベントから
 * CompensatoryGrantDayViewProjectorだけが作る派生データ(主キーは付与ID)。
 */
#[Fillable(['grant_id', 'user_id', 'work_date', 'granted_days', 'granted_minutes', 'status', 'used_days', 'used_minutes'])]
class CompensatoryGrantDayView extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'grant_id';

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'granted_days' => 'decimal:1',
            'granted_minutes' => 'integer',
            'used_days' => 'decimal:1',
            'used_minutes' => 'integer',
        ];
    }
}
