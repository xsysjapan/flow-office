<?php

namespace App\Http\Resources;

use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
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

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'work_date' => $this->work_date?->toDateString(),
            'status' => $this->status,
            'source' => $this->source,
            'actual_start_at' => LocalDateTime::formatWithOffsetMinutes($this->actual_start_at, $utcOffsetMinutes),
            'actual_end_at' => LocalDateTime::formatWithOffsetMinutes($this->actual_end_at, $utcOffsetMinutes),
            'utc_offset_minutes' => $utcOffsetMinutes,
            'work_type' => $this->work_type,
            'note' => $this->note,
            'is_locked' => $this->isLocked(),
            // today()でその日の勤務予定を一時的に載せている場合のみ含める(UC-A001 手順2)。
            'planned_start_at' => $this->planned_start_at,
            'planned_end_at' => $this->planned_end_at,
            'breaks' => $this->whenLoaded(
                'breaks',
                fn () => $this->breaks->map(fn ($break) => new AttendanceBreakResource($break, $utcOffsetMinutes)),
            ),
            'calculation' => $this->whenLoaded('calculation', fn () => $this->calculation ? new AttendanceDailyCalculationResource($this->calculation) : null),
            // 月60時間超残業(参考情報)。表示のたびに都度計算し、snapshotには含めない
            // (docs/07-usecases-attendance.md「月60時間超残業判定」参照)。
            'monthly_overtime' => $this->whenLoaded('calculation', fn () => $this->calculation
                ? app(MonthlyOvertimeCalculator::class)->calculateForDate($this->user_id, $this->work_date->toDateString())
                : null),
        ];
    }
}
