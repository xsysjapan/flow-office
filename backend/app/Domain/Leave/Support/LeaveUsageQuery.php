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

    /**
     * 直近1年間の日数を「申請中(submitted、未承認)」と「承認済み(approved)」に分けて集計する。
     * 残数(`*_grants.remaining_days`)は承認済み分の消化のみを反映するため、承認前でも
     * どれだけ申請が積み上がっているかを別枠で可視化するために使う
     * (勤怠編集時点で残数不足でも申請自体は成立させる方針のため、申請中の数字だけでは
     * 残数が正しく減っていないことを承認者・本人双方が把握できるようにする)。
     *
     * @return array{pending_days: float, approved_days: float}
     */
    public static function usageBreakdownWithinPastYear(
        string $userId,
        string $requestModelClass,
        ?int $specialLeaveTypeId = null,
    ): array {
        $sumForStatus = fn (string $status): float => (float) $requestModelClass::query()
            ->where('user_id', $userId)
            ->where('status', $status)
            ->where('target_date', '>=', Carbon::now()->subYear()->startOfDay())
            ->when($specialLeaveTypeId !== null, fn ($query) => $query->where('special_leave_type_id', $specialLeaveTypeId))
            ->sum('requested_days');

        return [
            'pending_days' => $sumForStatus('submitted'),
            'approved_days' => $sumForStatus('approved'),
        ];
    }
}
