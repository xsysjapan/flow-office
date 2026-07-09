<?php

namespace App\Http\Resources;

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
        // 対象社員本人のタイムゾーンのオフセットを付与する (docs/06-usecases-auth.md UC-003)。
        $timezone = $this->user->timezone;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'work_date' => $this->work_date?->toDateString(),
            'status' => $this->status,
            'source' => $this->source,
            'actual_start_at' => LocalDateTime::toIso8601($this->actual_start_at, $timezone),
            'actual_end_at' => LocalDateTime::toIso8601($this->actual_end_at, $timezone),
            'work_type' => $this->work_type,
            'note' => $this->note,
            'is_locked' => $this->isLocked(),
            // today()でその日の勤務予定を一時的に載せている場合のみ含める(UC-A001 手順2)。
            'planned_start_at' => $this->planned_start_at,
            'planned_end_at' => $this->planned_end_at,
            'breaks' => $this->whenLoaded(
                'breaks',
                fn () => $this->breaks->map(fn ($break) => new AttendanceBreakResource($break, $timezone)),
            ),
            'calculation' => $this->whenLoaded('calculation', fn () => $this->calculation ? new AttendanceDailyCalculationResource($this->calculation) : null),
        ];
    }
}
