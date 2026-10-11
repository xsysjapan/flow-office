<?php

namespace App\Domain\Attendance\Support;

use App\Models\AttendanceDayLeave;
use App\Models\PaidLeaveType;

/**
 * 休暇(有給・特別休暇・代休)を勤怠の日に適用してよいかの判定(勤怠文脈の業務ルール)。UI・DBに依存しない純粋な判定。
 *
 * 規則:
 * - 勤務予定日でない日には休暇を適用できない(休暇の種類ごとの文言)。
 * - 有給・特別休暇の時間休は、その日の所定労働時間が特定でき、時間数が所定労働時間の1日未満(0より大きい)であること。
 *   (代休の時間単位は時間数が正であることのみ確認済みで、所定労働時間との整合は問わない)
 */
final class LeaveApplicabilityPolicy
{
    /**
     * @param  string  $leaveKind  AttendanceDayLeave::KIND_*
     * @param  string  $unit  full|am_half|pm_half|hourly
     * @param  ?int  $minutes  時間休の分数(時間休のみ)
     * @param  bool  $isWorkingDay  対象日が勤務予定日か
     * @param  int  $prescribedMinutes  対象日の所定労働時間(分。特定できなければ0)
     * @return string|null 適用できない場合は理由、できればnull
     */
    public function check(string $leaveKind, string $unit, ?int $minutes, bool $isWorkingDay, int $prescribedMinutes): ?string
    {
        if (! $isWorkingDay) {
            return match ($leaveKind) {
                AttendanceDayLeave::KIND_PAID => '勤務予定日ではないため有給を申請できません。',
                AttendanceDayLeave::KIND_SPECIAL => '勤務予定日ではないため特別休暇を申請できません。',
                default => '勤務予定日ではないため代休を申請できません。',
            };
        }

        if ($unit !== PaidLeaveType::HOURLY || $leaveKind === AttendanceDayLeave::KIND_COMPENSATORY) {
            return null;
        }

        $isPaid = $leaveKind === AttendanceDayLeave::KIND_PAID;

        if ($prescribedMinutes <= 0) {
            return $isPaid
                ? '働き方が特定できないため時間休を申請できません。'
                : '働き方が特定できないため時間単位の特別休暇を申請できません。';
        }

        $days = round(($minutes ?? 0) / $prescribedMinutes, 1);

        if ($days <= 0 || $days >= 1) {
            return $isPaid
                ? '時間休として妥当な取得時間を指定してください。'
                : '時間単位として妥当な取得時間を指定してください。';
        }

        return null;
    }
}
