<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkCalendarDayResource;
use App\Http\Resources\WorkCalendarResource;
use App\Models\WorkCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * UC-C001: 年度カレンダーを作成する。
 */
class WorkCalendarController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WorkCalendarResource::collection(WorkCalendar::query()->orderByDesc('fiscal_year')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'fiscal_year' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'week_starts_on' => ['integer', 'between:1,7'],
        ]);

        $calendar = WorkCalendar::query()->create($data + ['status' => 'draft']);

        return (new WorkCalendarResource($calendar))->response()->setStatusCode(201);
    }

    public function publish(WorkCalendar $workCalendar): WorkCalendarResource
    {
        $workCalendar->update(['status' => 'published']);

        return new WorkCalendarResource($workCalendar);
    }

    /**
     * UC-C001 手順2〜4: 会社休日・祝日・法定/所定休日を一括登録する。
     */
    public function putDays(Request $request, WorkCalendar $workCalendar): AnonymousResourceCollection
    {
        $data = $request->validate([
            'days' => ['required', 'array'],
            'days.*.date' => ['required', 'date'],
            'days.*.day_type' => ['required', 'string'],
            'days.*.is_working_day' => ['boolean'],
            'days.*.is_legal_holiday' => ['boolean'],
            'days.*.is_company_holiday' => ['boolean'],
            'days.*.note' => ['nullable', 'string'],
        ]);

        foreach ($data['days'] as $day) {
            // 'date' はdateキャストのためDB上はdatetime文字列で保存される。
            // updateOrCreateの厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
            $calendarDay = $workCalendar->days()->whereDate('date', $day['date'])->first()
                ?? $workCalendar->days()->make(['date' => $day['date']]);

            $calendarDay->fill([
                'day_type' => $day['day_type'],
                'is_working_day' => $day['is_working_day'] ?? true,
                'is_legal_holiday' => $day['is_legal_holiday'] ?? false,
                'is_company_holiday' => $day['is_company_holiday'] ?? false,
                'note' => $day['note'] ?? null,
            ])->save();
        }

        return WorkCalendarDayResource::collection($workCalendar->days()->orderBy('date')->get());
    }
}
