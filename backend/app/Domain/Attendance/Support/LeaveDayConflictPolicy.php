<?php

namespace App\Domain\Attendance\Support;

use App\Models\PaidLeaveType;
use InvalidArgumentException;

/**
 * 同じ勤怠の日に休暇を追加してよいかの判定(論点7・仕様確定事項I)。UI・DBに依存しない純粋な判定。
 *
 * 規則:
 * - 既存か新規のどちらかが全休なら衝突。
 * - 同じ半休(午前半休同士・午後半休同士)は衝突。午前半休と午後半休は(種類を問わず)両立する。
 * - 休暇の合計が所定労働時間Pを超えたら衝突。合計は 全休=P、半休=intdiv(P,2)、時間休=minutes。
 *
 * 各休暇は ['unit' => full|am_half|pm_half|hourly, 'minutes' => ?int] の配列。
 * 呼び出し側は今回の連鎖を起こした休暇自身を existingLeaves から除いて渡す。
 */
final class LeaveDayConflictPolicy
{
    /**
     * @param  list<array{unit: string, minutes?: ?int}>  $existingLeaves
     * @param  array{unit: string, minutes?: ?int}  $newLeave
     * @return string|null 衝突なら理由、なければnull
     */
    public function check(array $existingLeaves, array $newLeave, int $prescribedMinutes): ?string
    {
        foreach ($existingLeaves as $existing) {
            if ($this->isFull($existing) || $this->isFull($newLeave)) {
                return '全休の日には他の休暇を併存できません。';
            }

            if ($this->isHalf($existing) && $existing['unit'] === $newLeave['unit']) {
                return '同じ半休(午前・午後)の休暇が既にあります。';
            }
        }

        $total = 0;
        foreach ([...$existingLeaves, $newLeave] as $leave) {
            $total += $this->minutesOf($leave, $prescribedMinutes);
        }

        if ($total > $prescribedMinutes) {
            return '休暇の合計が所定労働時間を超えます。';
        }

        return null;
    }

    /** @param  array{unit: string, minutes?: ?int}  $leave */
    private function isFull(array $leave): bool
    {
        return $leave['unit'] === PaidLeaveType::FULL;
    }

    /** @param  array{unit: string, minutes?: ?int}  $leave */
    private function isHalf(array $leave): bool
    {
        return $leave['unit'] === PaidLeaveType::AM_HALF || $leave['unit'] === PaidLeaveType::PM_HALF;
    }

    /** @param  array{unit: string, minutes?: ?int}  $leave */
    private function minutesOf(array $leave, int $prescribedMinutes): int
    {
        return match ($leave['unit']) {
            PaidLeaveType::FULL => $prescribedMinutes,
            PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF => intdiv($prescribedMinutes, 2),
            PaidLeaveType::HOURLY => (int) ($leave['minutes'] ?? 0),
            default => throw new InvalidArgumentException("未知の休暇単位です: {$leave['unit']}"),
        };
    }
}
