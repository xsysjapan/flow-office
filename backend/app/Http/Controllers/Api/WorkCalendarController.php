<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\CreateWorkCalendar;
use App\Domain\Attendance\Commands\PublishWorkCalendar;
use App\Domain\Attendance\Commands\UpdateWorkCalendarDays;
use App\Domain\EventSourcing\CommandBus;
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

    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'fiscal_year' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'week_starts_on' => ['integer', 'between:1,7'],
        ]);

        $calendar = $commandBus->dispatch(new CreateWorkCalendar(
            name: $data['name'],
            fiscalYear: $data['fiscal_year'],
            startsOn: $data['starts_on'],
            endsOn: $data['ends_on'],
            weekStartsOn: $data['week_starts_on'] ?? 1,
            createdByUserId: $request->user()->id,
        ));

        return (new WorkCalendarResource($calendar))->response()->setStatusCode(201);
    }

    public function publish(Request $request, WorkCalendar $workCalendar, CommandBus $commandBus): WorkCalendarResource
    {
        $calendar = $commandBus->dispatch(new PublishWorkCalendar(
            workCalendarId: $workCalendar->id,
            publishedByUserId: $request->user()->id,
        ));

        return new WorkCalendarResource($calendar);
    }

    /**
     * UC-C001 手順2〜4: 会社休日・祝日・法定/所定休日を一括登録する。
     */
    public function putDays(Request $request, WorkCalendar $workCalendar, CommandBus $commandBus): AnonymousResourceCollection
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

        $commandBus->dispatch(new UpdateWorkCalendarDays(
            workCalendarId: $workCalendar->id,
            days: $data['days'],
            updatedByUserId: $request->user()->id,
        ));

        return WorkCalendarDayResource::collection($workCalendar->days()->orderBy('date')->get());
    }
}
