<?php

namespace App\Domain\Leave\Support;

use App\Models\PaidLeaveType;

/**
 * 出勤率の日単位の判定(仕様確定事項F・論点9)。UI・DBに依存しない純粋な判定。
 * 有給(PaidLeaveSchedule)と特別休暇(SpecialLeave)の両文脈が使う(テーブルを読まない計算のため文脈間で共有する)。
 *
 * 入力は出勤率ビュー(leave_attendance_rate_days)の行と、その日の有効な休暇(申請中・承認済み。差戻し・取消は含めない)。
 * - 全休(full)の休暇が1つでもある、または午前半休(am_half)と午後半休(pm_half)が(種類を問わず)そろう日は全休とし、
 *   そろった半休の種類も全休の種類に含める(所定を半分にしない。仕様確定事項I)。
 * - 半休が1つだけ・時間休は部分休暇(partial)。
 * - 出勤として数えるのは、退勤済み ∪ 全休の休暇(3種) ∪ 対象の種類の部分休暇。対象の種類は呼び出し側が決める
 *   (AttendanceRateAssessorは有給、GrantScheduledSpecialLeaveHandlerは有給・特別休暇)。
 */
final class LeaveAttendanceRateJudgement
{
    public const KIND_PAID = 'paid';

    public const KIND_SPECIAL = 'special';

    public const KIND_COMPENSATORY = 'compensatory';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CLOCKED_OUT = 'clocked_out';

    /**
     * 有効な休暇の申請状態(申請中・承認済み)。
     *
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return [self::STATUS_SUBMITTED, self::STATUS_APPROVED];
    }

    /**
     * その日の有効な休暇から、全休の種類と部分休暇の種類を求める。
     *
     * @param  list<array{kind: string, unit: string}>  $activeLeaves
     * @return array{full: list<string>, partial: list<string>}
     */
    public static function coverage(array $activeLeaves): array
    {
        $full = [];
        $partial = [];
        $amHalf = [];
        $pmHalf = [];

        foreach ($activeLeaves as $leave) {
            if ($leave['unit'] === PaidLeaveType::FULL) {
                $full[] = $leave['kind'];
            } elseif ($leave['unit'] === PaidLeaveType::AM_HALF) {
                $amHalf[] = $leave['kind'];
            } elseif ($leave['unit'] === PaidLeaveType::PM_HALF) {
                $pmHalf[] = $leave['kind'];
            } else {
                $partial[] = $leave['kind'];
            }
        }

        if ($amHalf !== [] && $pmHalf !== []) {
            // 午前半休と午後半休がそろう日は全休(種類を問わない)。
            $full = array_merge($full, $amHalf, $pmHalf);
        } else {
            $partial = array_merge($partial, $amHalf, $pmHalf);
        }

        return [
            'full' => self::uniqueSorted($full),
            'partial' => self::uniqueSorted($partial),
        ];
    }

    /**
     * その日を出勤率の分子に数えるか。
     *
     * @param  list<string>  $fullKinds
     * @param  list<string>  $partialKinds
     * @param  list<string>  $countedPartialKinds  半休・時間休を出勤として数える休暇の種類
     */
    public static function countsAsAttended(bool $attended, array $fullKinds, array $partialKinds, array $countedPartialKinds): bool
    {
        if ($attended || $fullKinds !== []) {
            return true;
        }

        return array_intersect($partialKinds, $countedPartialKinds) !== [];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
