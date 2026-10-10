<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 勤怠の休暇ビュー(attendance_day_leaves)。休暇申請文脈のイベントから
 * AttendanceDayLeaveProjectorだけが書き込む派生データ。有効な休暇の判定は
 * App\Domain\Attendance\Support\AttendanceDayLeaves を経由して行う。
 */
#[Fillable([
    'leave_kind',
    'leave_request_id',
    'user_id',
    'work_date',
    'unit',
    'hours',
    'minutes',
    'special_leave_type_id',
    'workflow_request_id',
    'request_status',
    'source',
])]
class AttendanceDayLeave extends Model
{
    public const KIND_PAID = 'paid';

    public const KIND_SPECIAL = 'special';

    public const KIND_COMPENSATORY = 'compensatory';

    /** 有給・旧系統(旧 paid_leave.* イベント)。 */
    public const SOURCE_LEGACY_PAID = 'legacy_paid';

    /** 有給・cutover後の系統(paid_leave_account.usage_*)。 */
    public const SOURCE_PAID_ACCOUNT = 'paid_account';

    /** 有給・新系統(paid_leave_request.*)。 */
    public const SOURCE_PAID_REQUEST = 'paid_request';

    public const SOURCE_SPECIAL = 'special';

    public const SOURCE_COMPENSATORY = 'compensatory';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'hours' => 'float',
            'minutes' => 'integer',
            'special_leave_type_id' => 'integer',
        ];
    }
}
