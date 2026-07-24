<?php

namespace App\Domain\Attendance\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;

/**
 * 指定した勤務日(日次勤怠 `attendance_days` および、その日に属する打刻ログ
 * `attendance_punches`)が通常の編集・削除操作の対象にできるかを判定する。
 *
 * 締め後(`locked_at`設定後)は修正申請ワークフローを使う (docs/07-usecases-attendance.md
 * UC-A005)。加えて、月次が承認済み(`attendance.month_approved`)以降は、まだ締め
 * (`locked_at`)が設定されていなくても通常の編集・削除を禁止する(UC-A015)。承認後は
 * バックオフィス確認対象になるため、日次側の記録・その根拠となる打刻ログのいずれも
 * 承認時点の内容から変更させない(UC-A013/UC-A014)。
 */
class AttendanceEditGuard
{
    private const BLOCKED_MONTH_STATUSES = [AttendanceMonthStatus::APPROVED, AttendanceMonthStatus::CLOSED];

    /**
     * @throws DomainRuleException 編集・削除できない場合
     */
    public function assertMutable(?AttendanceDay $day, string $userId, string $workDate): void
    {
        $reason = $this->blockedReason($day, $userId, $workDate);
        if ($reason !== null) {
            throw new DomainRuleException($reason);
        }
    }

    public function isMutable(?AttendanceDay $day, string $userId, string $workDate): bool
    {
        return $this->blockedReason($day, $userId, $workDate) === null;
    }

    private function blockedReason(?AttendanceDay $day, string $userId, string $workDate): ?string
    {
        if ($day !== null && $day->isLocked()) {
            return '締め後の勤怠は修正申請から変更してください。';
        }

        $month = AttendanceMonth::query()
            ->where('user_id', $userId)
            ->where('year_month', substr($workDate, 0, 7))
            ->first();

        if ($month !== null && in_array($month->status, self::BLOCKED_MONTH_STATUSES, true)) {
            return '承認済みの月次勤怠に含まれる日次勤怠は修正申請から変更してください。';
        }

        return null;
    }
}
