<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkStyleResource;
use App\Models\WorkStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            'work_time_system' => ['required', 'string'],
            'prescribed_daily_minutes' => ['required', 'integer', 'min:1'],
            'prescribed_weekly_minutes' => ['required', 'integer', 'min:1'],
            'default_start_time' => ['nullable', 'date_format:H:i'],
            'default_end_time' => ['nullable', 'date_format:H:i'],
            'default_break_minutes' => ['integer', 'min:0'],
            'calendar_id' => ['required', 'exists:work_calendars,id'],
            'is_shift_based' => ['boolean'],
        ]);

        $workStyle = WorkStyle::query()->create($data);

        return (new WorkStyleResource($workStyle))->response()->setStatusCode(201);
    }
}
