<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkStyleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'employment_category_id' => $this->employment_category_id,
            'employment_category_code' => $this->whenLoaded('employmentCategory', fn () => $this->employmentCategory?->code),
            'work_time_system' => $this->work_time_system,
            'prescribed_daily_minutes' => $this->prescribed_daily_minutes,
            'prescribed_weekly_minutes' => $this->prescribed_weekly_minutes,
            'deemed_daily_minutes' => $this->deemed_daily_minutes,
            'variable_period_start_day' => $this->variable_period_start_day,
            'default_start_time' => $this->default_start_time,
            'default_end_time' => $this->default_end_time,
            'default_break_minutes' => $this->default_break_minutes,
            'rounding_unit_minutes' => $this->rounding_unit_minutes,
            'default_break_start_time' => $this->default_break_start_time,
            'default_break_end_time' => $this->default_break_end_time,
            'calendar_id' => $this->calendar_id,
            'is_shift_based' => $this->is_shift_based,
            'is_default' => $this->is_default,
            'system_generated' => $this->system_generated,
            'legal_holiday_rule' => $this->legal_holiday_rule,
            'four_week_period_start_date' => $this->four_week_period_start_date?->toDateString(),
            'max_consecutive_work_days' => $this->max_consecutive_work_days,
            'settlement_start_day' => $this->settlement_start_day,
            'core_time_enabled' => $this->core_time_enabled,
            'core_time_start' => $this->core_time_start,
            'core_time_end' => $this->core_time_end,
            'flexible_time_start' => $this->flexible_time_start,
            'flexible_time_end' => $this->flexible_time_end,
            // 指示書16.1節: 一覧画面の管理者向け集計列。WorkStyleController::indexでのみ
            // 設定される(WorkStyleUsageSummaryCalculator参照)。他の経路ではnull/空配列。
            'applied_employee_count' => $this->applied_employee_count,
            'active_shift_pattern_count' => $this->active_shift_pattern_count,
            'configuration_warnings' => $this->configuration_warnings ?? [],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
