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
use OpenApi\Attributes as OA;

/**
 * UC-C001: 年度カレンダーを作成する。
 */
#[OA\Tag(name: '勤務カレンダー', description: '会社カレンダーと休日設定')]
class WorkCalendarController extends Controller
{
    #[OA\Get(
        path: '/work-calendars',
        operationId: 'workCalendars.index',
        summary: '勤務カレンダー一覧を取得する',
        tags: ['勤務カレンダー'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(): AnonymousResourceCollection
    {
        return WorkCalendarResource::collection(WorkCalendar::query()->orderByDesc('fiscal_year')->get());
    }

    #[OA\Post(
        path: '/work-calendars',
        operationId: 'workCalendars.store',
        summary: '勤務カレンダーを作成する',
        tags: ['勤務カレンダー'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'fiscal_year', 'starts_on', 'ends_on'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'fiscal_year', type: 'integer'), new OA\Property(property: 'starts_on', type: 'string', format: 'date'), new OA\Property(property: 'ends_on', type: 'string', format: 'date'), new OA\Property(property: 'week_starts_on', type: 'integer')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
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

    #[OA\Post(
        path: '/work-calendars/{workCalendar}/publish',
        operationId: 'workCalendars.publish',
        summary: '勤務カレンダーを公開する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'workCalendar', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
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
    #[OA\Put(
        path: '/work-calendars/{workCalendar}/days',
        operationId: 'workCalendars.putDays',
        summary: '勤務カレンダーの日別設定を更新する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'workCalendar', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['days'], properties: [new OA\Property(property: 'days', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
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
