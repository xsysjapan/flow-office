<?php

namespace App\Domain\Attendance\Support;

use App\Models\AttendanceDayLeave;
use Illuminate\Database\Eloquent\Builder;

/**
 * 勤怠の休暇ビュー(attendance_day_leaves)の問い合わせ。有効な休暇(申請中 submitted・承認済み approved)
 * だけを返す。差戻し・取消の行は削除されていないため、ここで必ず除外する。
 *
 * 戻り値は値の配列(LeaveDayConflictPolicy・LeaveCalculationInput へそのまま渡せる形):
 * leave_kind, leave_request_id, user_id, work_date(Y-m-d), unit, hours, minutes,
 * special_leave_type_id, workflow_request_id, request_status, source
 */
final class AttendanceDayLeaves
{
    /** @return list<array<string, mixed>> */
    public function activeFor(string $userId, string $workDate): array
    {
        return $this->activeQuery($userId)
            ->where('work_date', $workDate)
            ->get()
            ->map(fn (AttendanceDayLeave $leave): array => $this->toArray($leave))
            ->values()
            ->all();
    }

    /**
     * 期間(両端を含む)の有効な休暇。Y-m-d文字列で指定する。
     *
     * @return list<array<string, mixed>>
     */
    public function activeForRange(string $userId, string $from, string $to): array
    {
        return $this->activeQuery($userId)
            ->whereBetween('work_date', [$from, $to])
            ->get()
            ->map(fn (AttendanceDayLeave $leave): array => $this->toArray($leave))
            ->values()
            ->all();
    }

    /**
     * その日が全休か(有効な休暇で判定。FullDayLeavePolicy参照)。
     */
    public function isFullDayLeave(string $userId, string $workDate): bool
    {
        return FullDayLeavePolicy::isFullDay($this->activeFor($userId, $workDate));
    }

    /**
     * 期間内(両端を含む)で全休の日の一覧(Y-m-d)。有効な休暇の日ごとに判定する。
     *
     * @return list<string>
     */
    public function fullDayLeaveDatesIn(string $userId, string $from, string $to): array
    {
        return collect($this->activeForRange($userId, $from, $to))
            ->groupBy('work_date')
            ->filter(fn ($leaves): bool => FullDayLeavePolicy::isFullDay($leaves->values()->all()))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * 期間内(両端を含む)に、指定の種類の申請中(submitted)の休暇があるか。差戻し・承認済みは含まない。
     */
    public function hasSubmittedOfKindIn(string $userId, string $leaveKind, string $from, string $to): bool
    {
        return AttendanceDayLeave::query()
            ->where('user_id', $userId)
            ->where('leave_kind', $leaveKind)
            ->where('request_status', AttendanceDayLeave::STATUS_SUBMITTED)
            ->whereBetween('work_date', [$from, $to])
            ->exists();
    }

    /**
     * 休暇1件(申請ID)の行を状態によらず返す。差戻し・取消の行も返す(対象日・利用者を引くため)。
     * 行が無ければ null。
     *
     * @return array<string, mixed>|null
     */
    public function findByRequest(string $leaveKind, string $leaveRequestId): ?array
    {
        $leave = AttendanceDayLeave::query()
            ->where('leave_kind', $leaveKind)
            ->where('leave_request_id', $leaveRequestId)
            ->first();

        return $leave === null ? null : $this->toArray($leave);
    }

    private function activeQuery(string $userId): Builder
    {
        return AttendanceDayLeave::query()
            ->where('user_id', $userId)
            ->whereIn('request_status', [AttendanceDayLeave::STATUS_SUBMITTED, AttendanceDayLeave::STATUS_APPROVED])
            ->orderBy('work_date')
            ->orderBy('leave_kind')
            ->orderBy('leave_request_id');
    }

    /** @return array<string, mixed> */
    private function toArray(AttendanceDayLeave $leave): array
    {
        return [
            'leave_kind' => $leave->leave_kind,
            'leave_request_id' => $leave->leave_request_id,
            'user_id' => $leave->user_id,
            'work_date' => (string) $leave->work_date,
            'unit' => $leave->unit,
            'hours' => $leave->hours,
            'minutes' => $leave->minutes,
            'special_leave_type_id' => $leave->special_leave_type_id,
            'workflow_request_id' => $leave->workflow_request_id,
            'request_status' => $leave->request_status,
            'source' => $leave->source,
        ];
    }
}
