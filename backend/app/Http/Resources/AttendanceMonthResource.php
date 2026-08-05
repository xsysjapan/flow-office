<?php

namespace App\Http\Resources;

use App\Domain\Attendance\Services\LegalHolidayRequirementChecker;
use App\Domain\Attendance\Services\WeeklyOvertimeCalculator;
use App\Models\AttendanceDay;
use App\Models\CompensatoryLeaveGrant;
use App\Models\DayClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class AttendanceMonthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'year_month' => $this->year_month,
            'status' => $this->status,
            'approver' => new UserResource($this->whenLoaded('approver')),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'return_comment' => $this->return_comment,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'snapshot' => $this->snapshot_json,
            // UC-C005: シフト制の勤務形態のみ対象。承認をブロックせず警告として表示する。
            'legal_holiday_warnings' => app(LegalHolidayRequirementChecker::class)->check($this->user_id, $this->year_month),
            // 週40時間(労基法32条)の週ごとの内訳。この内訳自体はsnapshotには含めず、表示のたびに
            // 都度計算する(週次勤怠は日次勤怠の編集ビューであり、月へ合算する独立集計単位ではない
            // ため)。月内の全週を合算した確定値は`monthly_calculation_totals.weekly_statutory_
            // excess_overtime_minutes`(月次提出後はsnapshot_jsonの同名キー)を参照する。
            'weekly_overtime_reference' => app(WeeklyOvertimeCalculator::class)->calculateForMonth($this->user_id, $this->year_month),
            // 代休: 使用済みのGrantの元になった休日出勤実績が取り消された・休日でなくなった
            // 場合の整合性警告(承認をブロックせず警告として表示するのみ。
            // .claude/skills配下add-projection等と同じ「派生データ」の考え方で、正データ
            // (attendance_days/compensatory_leave_grants)自体はここでは書き換えない)。
            'compensatory_leave_warnings' => $this->compensatoryLeaveWarnings(),
        ];
    }

    /**
     * @return list<string>
     */
    private function compensatoryLeaveWarnings(): array
    {
        $usedGrants = CompensatoryLeaveGrant::query()
            ->where('user_id', $this->user_id)
            ->where('work_date', 'like', "{$this->year_month}%")
            ->where(fn ($q) => $q->where('used_days', '>', 0)->orWhere('used_minutes', '>', 0))
            ->get();

        if ($usedGrants->isEmpty()) {
            return [];
        }

        $attendanceDays = AttendanceDay::query()
            ->whereIn('id', $usedGrants->pluck('attendance_day_id'))
            ->get()
            ->keyBy('id');

        $warnings = [];

        foreach ($usedGrants as $grant) {
            /** @var Collection<string, AttendanceDay> $attendanceDays */
            $day = $attendanceDays->get($grant->attendance_day_id);

            $stillHolidayWork = $day !== null
                && in_array($day->day_classification, [DayClassification::PRESCRIBED_HOLIDAY, DayClassification::LEGAL_HOLIDAY], true);

            if (! $stillHolidayWork) {
                $warnings[] = "{$grant->work_date->toDateString()} の休日出勤の実績が取り消されたため、既に使用した代休との整合性を確認してください。";
            }
        }

        return $warnings;
    }
}
