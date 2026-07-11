<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkStyleResource;
use App\Models\WorkStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * UC-C002: 勤務形態を作成する。
 */
class WorkStyleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WorkStyleResource::collection(WorkStyle::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:work_styles,code'],
            'name' => ['required', 'string', 'max:100'],
            'work_time_system' => ['required', 'string', Rule::in(['fixed', 'shortened', 'shift_based', 'discretionary'])],
            'prescribed_daily_minutes' => ['required', 'integer', 'min:1'],
            'prescribed_weekly_minutes' => ['required', 'integer', 'min:1'],
            'default_start_time' => ['nullable', 'date_format:H:i'],
            'default_end_time' => ['nullable', 'date_format:H:i'],
            'default_break_minutes' => ['integer', 'min:0'],
            'calendar_id' => ['required', 'exists:work_calendars,id'],
            'is_shift_based' => ['boolean'],
            'legal_holiday_rule' => ['nullable', Rule::in([
                WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
                WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS,
            ])],
            'four_week_period_start_date' => [
                'nullable', 'date',
                Rule::requiredIf($request->input('legal_holiday_rule') === WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS),
            ],
        ]);

        $data['legal_holiday_rule'] ??= WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY;

        $workStyle = WorkStyle::query()->create($data);

        return (new WorkStyleResource($workStyle))->response()->setStatusCode(201);
    }
}
