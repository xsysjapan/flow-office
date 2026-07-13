<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserWorkStyleMonthlyAssignmentResource;
use App\Models\UserWorkStyleMonthlyAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ユーザーの月次働き方割当。11月からシフト勤務、10月までは通常勤務のように、
 * 月ごとの働き方切り替えを過去月を壊さず履歴として残す(docs/08-usecases-calendar-shift.md)。
 */
class UserWorkStyleMonthlyAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $assignments = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $data['user_id'])
            ->with('workStyle')
            ->orderBy('year_month')
            ->get();

        return UserWorkStyleMonthlyAssignmentResource::collection($assignments);
    }

    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'year_month' => ['required', 'date_format:Y-m'],
            'work_style_id' => ['required', 'integer', 'exists:work_styles,id'],
        ]);

        $assignment = $commandBus->dispatch(new AssignUserWorkStyleForMonth(
            userId: $data['user_id'],
            yearMonth: $data['year_month'],
            workStyleId: $data['work_style_id'],
            assignedByUserId: $request->user()->id,
        ));

        return (new UserWorkStyleMonthlyAssignmentResource($assignment->load('workStyle')))
            ->response()->setStatusCode(201);
    }
}
