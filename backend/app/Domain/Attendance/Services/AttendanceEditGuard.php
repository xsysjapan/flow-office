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
 * UC-A005)。加えて、月次が提出済み(`attendance.month_submitted`)以降は、まだ締め
 * (`locked_at`)が設定されていなくても通常の編集・削除を禁止する(UC-A008/UC-A015)。提出後は
 * 承認者による確認・バックオフィス確認対象になるため、日次側の記録・その根拠となる打刻ログの
 * いずれも提出時点の内容から変更させない(UC-A013/UC-A014)。個々の`AttendanceDay`単位では
 * なく月次のProjection(`attendance_months.status`)を見て判定するため、休日など
 * `AttendanceDay`レコード自体が存在しない日にも一律にロックがかかる。差戻し(returned)に
 * なった月は再度編集可能に戻る。
 */
class AttendanceEditGuard
{
    private const BLOCKED_MONTH_STATUSES = [
        AttendanceMonthStatus::SUBMITTED,
        AttendanceMonthStatus::APPROVED,
        AttendanceMonthStatus::CLOSED,
    ];

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
            return '提出済み以降の月次勤怠に含まれる日次勤怠は修正申請から変更してください。';
        }

        return null;
    }
}
