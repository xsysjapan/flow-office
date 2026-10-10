<?php

namespace App\Http\Resources;

use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 内部ではタイムゾーンなしの壁時計時刻を保存しているため、APIへ出力する際は
        // その勤務日自身が保持するUTCオフセット(utc_offset_minutes)を付与する。
        // 海外出張などで勤務日ごとに現地時刻が変わるため、社員本人の既定タイムゾーンでは
        // なくこのオフセットを使う (docs/03-architecture.md 3.4)。
        $utcOffsetMinutes = $this->utc_offset_minutes;
        $workDate = $this->work_date?->toDateString();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'work_date' => $workDate,
            'status' => $this->status,
            'source' => $this->source,
            'actual_start_at' => LocalDateTime::formatWithOffsetMinutes($this->actual_start_at, $utcOffsetMinutes),
            'actual_end_at' => LocalDateTime::formatWithOffsetMinutes($this->actual_end_at, $utcOffsetMinutes),
            'utc_offset_minutes' => $utcOffsetMinutes,
            'work_type' => $this->work_type,
            'work_location_type' => $this->work_location_type,
            'day_classification' => $this->day_classification,
            'note' => $this->note,
            // 締め(locked_at)だけでなく、月次が提出済み以降のロックも合わせて反映する
            // (AttendanceEditGuard参照。previewAttendancePatternと同じ判定)。
            'is_locked' => $workDate === null
                ? $this->isLocked()
                : ! app(AttendanceEditGuard::class)->isMutable($this->resource, $this->user_id, $workDate),
            // today()でその日の勤務予定を一時的に載せている場合のみ含める(UC-A001 手順2)。
            'planned_start_at' => $this->planned_start_at,
            'planned_end_at' => $this->planned_end_at,
            'breaks' => $this->whenLoaded(
                'breaks',
                fn () => $this->breaks->map(fn ($break) => new AttendanceBreakResource($break, $utcOffsetMinutes)),
            ),
            // 欠勤・特別休暇の区間(有給休暇は含まない。UC-A005「不就労時間の処理区分」参照)。
            'leave_segments' => $this->whenLoaded(
                'leaveSegments',
                fn () => $this->leaveSegments->map(fn ($segment) => new AttendanceLeaveSegmentResource($segment, $utcOffsetMinutes)),
            ),
            'calculation' => $this->whenLoaded('calculation', fn () => $this->calculation ? new AttendanceDailyCalculationResource($this->calculation) : null),
            // その日の休暇(休暇ビュー attendance_day_leaves の申請中・承認済み)。休暇ラベル・全休判定は
            // この一覧から行う(作業内容 work_type は休暇を表さない: 論点10)。複数あれば複数返す。
            'leaves' => $this->leavesOn($workDate),
            // 月60時間超残業(参考情報)。表示のたびに都度計算し、snapshotには含めない
            // (docs/07-usecases-attendance.md「月60時間超残業判定」参照)。
            'monthly_overtime' => $this->whenLoaded('calculation', fn () => $this->calculation
                ? app(MonthlyOvertimeCalculator::class)->calculateForDate($this->user_id, $this->work_date->toDateString())
                : null),
        ];
    }

    /**
     * @return list<array{leave_kind: string, unit: string, hours: float|null, minutes: int|null, special_leave_type_id: int|null, request_id: string, workflow_request_id: string|null, request_status: string}>
     */
    private function leavesOn(?string $workDate): array
    {
        if ($workDate === null || $this->user_id === null) {
            return [];
        }

        return collect(app(AttendanceDayLeaves::class)->activeFor($this->user_id, $workDate))
            ->map(fn (array $leave): array => [
                'leave_kind' => $leave['leave_kind'],
                'unit' => $leave['unit'],
                'hours' => $leave['hours'] === null ? null : (float) $leave['hours'],
                'minutes' => $leave['minutes'],
                'special_leave_type_id' => $leave['special_leave_type_id'],
                'request_id' => $leave['leave_request_id'],
                'workflow_request_id' => $leave['workflow_request_id'],
                'request_status' => $leave['request_status'],
            ])
            ->values()
            ->all();
    }
}
