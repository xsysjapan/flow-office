<?php

namespace App\Domain\AttendanceImport\Services;

use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveUsage;
use App\Models\PunchStatus;
use App\Models\SpecialLeaveUsage;
use Illuminate\Support\Carbon;

/**
 * docs/26-usecases-monthly-import.md「差異検出」。作業報告書由来の提案データと、既存の
 * 打刻・勤怠・勤務予定・休暇消化を比較する。実際のAI推定(Claude側)とは別に、勤怠管理API側で
 * 機械的に検証できる項目だけを扱う(docs/03-architecture.md 3.7「AIは勤怠ルールを決定しない」)。
 *
 * @phpstan-type Difference array{code: string, severity: string, message: string}
 */
class AttendanceDifferenceDetector
{
    public function __construct(private readonly AttendanceEditGuard $guard) {}

    /**
     * @param  array<string, mixed>  $proposed  {date, startTime, endTime, breaks[], workLocation, ...}
     * @return array{existing: array<string, mixed>|null, differences: array<int, Difference>}
     */
    public function detect(int $userId, string $targetMonth, array $proposed): array
    {
        $workDate = $proposed['date'];
        $differences = [];

        if (! str_starts_with($workDate, $targetMonth)) {
            $differences[] = $this->error('OUT_OF_MONTH', "{$workDate}は対象月({$targetMonth})外の勤務日です。");
        }

        $day = AttendanceDay::query()->with('breaks')->where('user_id', $userId)->whereDate('work_date', $workDate)->first();
        $punchExists = AttendancePunch::query()->where('user_id', $userId)->whereDate('work_date', $workDate)->where('status', PunchStatus::ACTIVE)->exists();

        $existing = null;
        if ($day !== null) {
            $existing = [
                'id' => $day->id,
                'start_time' => $day->actual_start_at?->format('H:i'),
                'end_time' => $day->actual_end_at?->format('H:i'),
                'breaks' => $day->breaks->map(fn ($break) => [
                    'start_time' => $break->break_start_at?->format('H:i'),
                    'end_time' => $break->break_end_at?->format('H:i'),
                ])->all(),
                'locked' => $day->isLocked(),
            ];

            if (! $this->guard->isMutable($day, $userId, $workDate)) {
                $differences[] = $this->error('ATTENDANCE_MONTH_LOCKED', "{$workDate}が属する月は既に締め済み・承認済みのため登録できません。");
            }

            $proposedStart = $proposed['startTime'] ?? null;
            $proposedEnd = $proposed['endTime'] ?? null;

            if ($proposedStart !== null && $day->actual_start_at !== null && $proposedStart !== $day->actual_start_at->format('H:i')) {
                $differences[] = $this->warning('START_TIME_DIFF', "出勤時刻が既存の実績({$day->actual_start_at->format('H:i')})と異なります(報告書: {$proposedStart})。");
            }
            if ($proposedEnd !== null && $day->actual_end_at !== null && $proposedEnd !== $day->actual_end_at->format('H:i')) {
                $differences[] = $this->warning('END_TIME_DIFF', "退勤時刻が既存の実績({$day->actual_end_at->format('H:i')})と異なります(報告書: {$proposedEnd})。");
            }
        } elseif (! $punchExists && ($proposed['startTime'] ?? null) !== null) {
            $differences[] = $this->warning('MISSING_EXISTING_ATTENDANCE', "{$workDate}は報告書に勤務がありますが、勤怠が登録されていません。");
        }

        if (($proposed['startTime'] ?? null) !== null && ($proposed['endTime'] ?? null) !== null) {
            if ($proposed['startTime'] >= $proposed['endTime']) {
                $differences[] = $this->error('INVALID_TIME_RANGE', '開始時刻が終了時刻以降になっています。');
            }
        }

        $hasPaidLeave = PaidLeaveUsage::query()->where('user_id', $userId)->whereDate('used_on', $workDate)->exists();
        $hasSpecialLeave = SpecialLeaveUsage::query()->where('user_id', $userId)->whereDate('used_on', $workDate)->exists();
        if (($hasPaidLeave || $hasSpecialLeave) && ($proposed['startTime'] ?? null) !== null) {
            $differences[] = $this->warning('LEAVE_CONFLICT', '有給休暇または特別休暇の消化と勤務時間が重複しています。');
        }

        $shiftAssignment = EmployeeShiftAssignment::query()->where('user_id', $userId)->whereDate('work_date', $workDate)->first();
        if ($shiftAssignment?->is_legal_holiday && ($proposed['startTime'] ?? null) !== null) {
            $differences[] = $this->warning('HOLIDAY_WORK_REQUIRES_APPLICATION', '法定休日の勤務のため、休日出勤申請の要否を確認してください。');
        }

        return ['existing' => $existing, 'differences' => $differences];
    }

    /**
     * 対象月に既存の勤怠(attendance_days)があるが、報告書側に記載が無い勤務日を検出する
     * (docs/26「報告書に勤務がないが勤怠が存在する」)。
     *
     * @param  array<int, string>  $proposedDates
     * @return array<int, string>
     */
    public function findDatesMissingFromReport(int $userId, string $targetMonth, array $proposedDates): array
    {
        return AttendanceDay::query()
            ->where('user_id', $userId)
            ->where('work_date', 'like', "{$targetMonth}%")
            ->whereNotNull('actual_start_at')
            ->pluck('work_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->reject(fn ($date) => in_array($date, $proposedDates, true))
            ->values()
            ->all();
    }

    /**
     * @return Difference
     */
    private function error(string $code, string $message): array
    {
        return ['code' => $code, 'severity' => 'error', 'message' => $message];
    }

    /**
     * @return Difference
     */
    private function warning(string $code, string $message): array
    {
        return ['code' => $code, 'severity' => 'warning', 'message' => $message];
    }
}
