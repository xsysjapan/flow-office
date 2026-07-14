<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\CreateShiftPattern;
use App\Domain\Attendance\Commands\UpdateShiftPattern;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftPatternResource;
use App\Models\ShiftPattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * UC-C004 手順2: シフトパターン(日勤/準夜勤/深夜勤/公休/明け休み等)を登録・編集する。
 */
#[OA\Tag(name: 'シフトパターン', description: 'シフト勤務の勤務パターン')]
class ShiftPatternController extends Controller
{
    #[OA\Get(
        path: '/shift-patterns',
        operationId: 'shiftPatterns.index',
        summary: 'シフトパターン一覧を取得する',
        tags: ['シフトパターン'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(): AnonymousResourceCollection
    {
        return ShiftPatternResource::collection(ShiftPattern::query()->orderBy('code')->get());
    }

    #[OA\Post(
        path: '/shift-patterns',
        operationId: 'shiftPatterns.store',
        summary: 'シフトパターンを作成する',
        tags: ['シフトパターン'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name'], properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'start_time', type: 'string', nullable: true), new OA\Property(property: 'end_time', type: 'string', nullable: true), new OA\Property(property: 'crosses_midnight', type: 'boolean'), new OA\Property(property: 'break_minutes', type: 'integer'), new OA\Property(property: 'prescribed_work_minutes', type: 'integer')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:shift_patterns,code'],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'crosses_midnight' => ['boolean'],
            'break_minutes' => ['integer', 'min:0'],
            'break_start_time' => ['nullable', 'date_format:H:i'],
            'break_end_time' => ['nullable', 'date_format:H:i'],
            'prescribed_work_minutes' => ['integer', 'min:0'],
        ]);

        $pattern = $commandBus->dispatch(new CreateShiftPattern(
            code: $data['code'],
            name: $data['name'],
            startTime: $data['start_time'] ?? null,
            endTime: $data['end_time'] ?? null,
            crossesMidnight: $data['crosses_midnight'] ?? false,
            breakMinutes: $data['break_minutes'] ?? 0,
            breakStartTime: $data['break_start_time'] ?? null,
            breakEndTime: $data['break_end_time'] ?? null,
            prescribedWorkMinutes: $data['prescribed_work_minutes'] ?? 0,
            createdByUserId: $request->user()->id,
        ));

        return (new ShiftPatternResource($pattern))->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/shift-patterns/{shiftPattern}',
        operationId: 'shiftPatterns.update',
        summary: 'シフトパターンを更新する',
        tags: ['シフトパターン'],
        parameters: [new OA\Parameter(name: 'shiftPattern', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'start_time', type: 'string', nullable: true), new OA\Property(property: 'end_time', type: 'string', nullable: true), new OA\Property(property: 'crosses_midnight', type: 'boolean'), new OA\Property(property: 'break_minutes', type: 'integer'), new OA\Property(property: 'prescribed_work_minutes', type: 'integer')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function update(Request $request, ShiftPattern $shiftPattern, CommandBus $commandBus): ShiftPatternResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'crosses_midnight' => ['boolean'],
            'break_minutes' => ['integer', 'min:0'],
            'break_start_time' => ['nullable', 'date_format:H:i'],
            'break_end_time' => ['nullable', 'date_format:H:i'],
            'prescribed_work_minutes' => ['integer', 'min:0'],
        ]);

        $pattern = $commandBus->dispatch(new UpdateShiftPattern(
            shiftPatternId: $shiftPattern->id,
            name: $data['name'],
            startTime: $data['start_time'] ?? null,
            endTime: $data['end_time'] ?? null,
            crossesMidnight: $data['crosses_midnight'] ?? false,
            breakMinutes: $data['break_minutes'] ?? 0,
            breakStartTime: $data['break_start_time'] ?? null,
            breakEndTime: $data['break_end_time'] ?? null,
            prescribedWorkMinutes: $data['prescribed_work_minutes'] ?? 0,
            updatedByUserId: $request->user()->id,
        ));

        return new ShiftPatternResource($pattern);
    }
}
