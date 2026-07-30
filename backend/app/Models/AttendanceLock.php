<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 勤怠ロック (UC-A008/UC-A010)。月次勤怠の提出時に対象期間の日次勤怠を編集不可にし、
 * 差戻し時に解除する。`AttendanceMonthProjector`がAttendanceMonthLocked/AttendanceMonthUnlocked
 * イベントから作成・更新する(正データはイベント、この行は再生成可能なProjectionという
 * 位置づけ)。
 *
 * `scope_type`は将来週・日単位のロックにも対応できるよう用意しているが、現時点で発行される
 * のは`month`のみ。有効なロックかどうかは`unlocked_at`がnullかどうかで判定する。
 */
#[Fillable(['scope_type', 'period_start_date', 'period_end_date', 'user_id', 'locked_at', 'unlocked_at', 'workflow_request_id'])]
class AttendanceLock extends Model
{
    public const SCOPE_MONTH = 'month';

    public const SCOPE_WEEK = 'week';

    public const SCOPE_DAY = 'day';

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'locked_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 指定した勤務日(work_date)をカバーする、現在有効な(解除されていない)ロックが
     * 対象社員に存在するかを判定する。
     */
    public static function hasActiveLockCovering(string $userId, string $workDate): bool
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereDate('period_start_date', '<=', $workDate)
            ->whereDate('period_end_date', '>=', $workDate)
            ->whereNull('unlocked_at')
            ->exists();
    }
}
