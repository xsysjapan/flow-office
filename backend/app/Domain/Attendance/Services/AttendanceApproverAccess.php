<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceMonth;

/**
 * UC-A009: 承認者に指定された月次勤怠の対象社員について、日次・週次・打刻ログの閲覧のみを
 * 追加で許可するための判定。承認者は都度指定であり管理者に限らないため(CLAUDE.md「承認者は
 * 都度指定」)、admin判定とは別に用意する。書き込み系操作(作成・編集・削除)の権限には使わない。
 */
class AttendanceApproverAccess
{
    /**
     * @param  string[]  $yearMonths  参照対象の日付から導出しうる年月の候補(週次は月をまたぐ
     *                                ことがあるため複数渡せるようにする)。
     */
    public function isApproverForAnyYearMonth(string $requestingUserId, string $ownerId, array $yearMonths): bool
    {
        if ($yearMonths === []) {
            return false;
        }

        return AttendanceMonth::query()
            ->where('user_id', $ownerId)
            ->where('approver_user_id', $requestingUserId)
            ->whereIn('year_month', array_unique($yearMonths))
            ->exists();
    }
}
