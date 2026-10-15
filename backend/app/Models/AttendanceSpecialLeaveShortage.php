<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 勤怠側の特別休暇の残数不足のビュー(消化記録ごとの未充当日数)。特別休暇口座のイベントから
 * AttendanceSpecialLeaveShortageProjectorだけが作る派生データ(主キーは消化記録ID)。
 * work_dateは日付文字列(Y-m-d)のまま扱う(キャストしない)。
 */
#[Fillable(['usage_id', 'user_id', 'work_date', 'special_leave_request_id', 'special_leave_type_id', 'used_days', 'used_minutes', 'unallocated_days'])]
class AttendanceSpecialLeaveShortage extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'usage_id';

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'special_leave_type_id' => 'integer',
            'used_days' => 'decimal:1',
            'used_minutes' => 'integer',
            'unallocated_days' => 'decimal:1',
        ];
    }
}
