<?php

namespace App\Domain\Leave\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 承認者が承認時に「直近1年間で対象社員がこの休暇をどれだけ取得しているか」を
 * 参考にできるようにするための集計(自動付与に依らない目視判断の補助。今のところ
 * 特定の日数上限をシステム側で強制するルールではない)。対象は申請中(submitted)・
 * 承認済み(approved)の行のみとし、差戻し・取消済みは含めない。
 *
 * @param  class-string<Model>  $requestModelClass  PaidLeaveRequest::class または SpecialLeaveRequest::class
 */
final class LeaveUsageQuery
{
    public static function usedDaysWithinPastYear(
        string $userId,
        string $requestModelClass,
        ?int $specialLeaveTypeId = null,
    ): float {
        return (float) $requestModelClass::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['submitted', 'approved'])
            ->where('target_date', '>=', Carbon::now()->subYear()->startOfDay())
            ->when($specialLeaveTypeId !== null, fn ($query) => $query->where('special_leave_type_id', $specialLeaveTypeId))
            ->sum('requested_days');
    }
}
