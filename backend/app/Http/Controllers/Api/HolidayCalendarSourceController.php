<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\DisableHolidayCalendarSource;
use App\Domain\Attendance\Commands\RegisterHolidayCalendarSource;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\HolidayCalendarSourceResource;
use App\Models\HolidayCalendarSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * UC-C012: 祝日iCalendarソースを同期する。
 */
#[OA\Tag(name: '祝日iCalendar同期', description: '祝日iCalendarソースの登録・同期')]
class HolidayCalendarSourceController extends Controller
{
    #[OA\Post(
        path: '/holiday-calendar-sources',
        operationId: 'holidayCalendarSources.store',
        summary: '祝日iCalendarソースを登録する',
        tags: ['祝日iCalendar同期'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'ics_url'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'ics_url', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ics_url' => ['required', 'string', 'url', 'max:500'],
        ]);

        $source = $commandBus->dispatch(new RegisterHolidayCalendarSource(
            name: $data['name'],
            icsUrl: $data['ics_url'],
            registeredByUserId: $request->user()->id,
        ));

        return (new HolidayCalendarSourceResource($source))->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/holiday-calendar-sources/{holidayCalendarSource}/sync',
        operationId: 'holidayCalendarSources.sync',
        summary: '祝日iCalendarソースを手動同期する',
        tags: ['祝日iCalendar同期'],
        parameters: [new OA\Parameter(name: 'holidayCalendarSource', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function sync(Request $request, HolidayCalendarSource $holidayCalendarSource, CommandBus $commandBus): HolidayCalendarSourceResource
    {
        $source = $commandBus->dispatch(new SyncHolidayCalendarSource(
            holidayCalendarSourceId: $holidayCalendarSource->id,
            syncedByUserId: $request->user()->id,
        ));

        return new HolidayCalendarSourceResource($source);
    }

    #[OA\Post(
        path: '/holiday-calendar-sources/{holidayCalendarSource}/disable',
        operationId: 'holidayCalendarSources.disable',
        summary: '祝日iCalendarソースを無効化する',
        tags: ['祝日iCalendar同期'],
        parameters: [new OA\Parameter(name: 'holidayCalendarSource', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function disable(Request $request, HolidayCalendarSource $holidayCalendarSource, CommandBus $commandBus): HolidayCalendarSourceResource
    {
        $source = $commandBus->dispatch(new DisableHolidayCalendarSource(
            holidayCalendarSourceId: $holidayCalendarSource->id,
            disabledByUserId: $request->user()->id,
        ));

        return new HolidayCalendarSourceResource($source);
    }
}
