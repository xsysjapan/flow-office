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

/**
 * UC-C004 手順2: シフトパターン(日勤/準夜勤/深夜勤/公休/明け休み等)を登録・編集する。
 */
class ShiftPatternController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ShiftPatternResource::collection(ShiftPattern::query()->orderBy('code')->get());
    }

    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:shift_patterns,code'],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'crosses_midnight' => ['boolean'],
            'break_minutes' => ['integer', 'min:0'],
            'prescribed_work_minutes' => ['integer', 'min:0'],
        ]);

        $pattern = $commandBus->dispatch(new CreateShiftPattern(
            code: $data['code'],
            name: $data['name'],
            startTime: $data['start_time'] ?? null,
            endTime: $data['end_time'] ?? null,
            crossesMidnight: $data['crosses_midnight'] ?? false,
            breakMinutes: $data['break_minutes'] ?? 0,
            prescribedWorkMinutes: $data['prescribed_work_minutes'] ?? 0,
            createdByUserId: $request->user()->id,
        ));

        return (new ShiftPatternResource($pattern))->response()->setStatusCode(201);
    }

    public function update(Request $request, ShiftPattern $shiftPattern, CommandBus $commandBus): ShiftPatternResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'crosses_midnight' => ['boolean'],
            'break_minutes' => ['integer', 'min:0'],
            'prescribed_work_minutes' => ['integer', 'min:0'],
        ]);

        $pattern = $commandBus->dispatch(new UpdateShiftPattern(
            shiftPatternId: $shiftPattern->id,
            name: $data['name'],
            startTime: $data['start_time'] ?? null,
            endTime: $data['end_time'] ?? null,
            crossesMidnight: $data['crosses_midnight'] ?? false,
            breakMinutes: $data['break_minutes'] ?? 0,
            prescribedWorkMinutes: $data['prescribed_work_minutes'] ?? 0,
            updatedByUserId: $request->user()->id,
        ));

        return new ShiftPatternResource($pattern);
    }
}
