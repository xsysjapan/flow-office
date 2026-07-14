<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\RemoveUserWorkStyleMonthlyAssignment;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserWorkStyleMonthlyAssignmentResource;
use App\Models\UserWorkStyleMonthlyAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * ユーザーの月次働き方割当。11月からシフト勤務、10月までは通常勤務のように、
 * 月ごとの働き方切り替えを過去月を壊さず履歴として残す(docs/08-usecases-calendar-shift.md)。
 */
#[OA\Tag(name: '月次勤務形態割当', description: '社員別の月次勤務形態割当')]
class UserWorkStyleMonthlyAssignmentController extends Controller
{
    #[OA\Get(
        path: '/user-work-style-monthly-assignments',
        operationId: 'userWorkStyleMonthlyAssignments.index',
        summary: '月次勤務形態割当一覧を取得する',
        tags: ['月次勤務形態割当'],
        parameters: [new OA\Parameter(name: 'user_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
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

    #[OA\Post(
        path: '/user-work-style-monthly-assignments',
        operationId: 'userWorkStyleMonthlyAssignments.store',
        summary: '月次勤務形態を割り当てる',
        tags: ['月次勤務形態割当'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'year_month', 'work_style_id'], properties: [new OA\Property(property: 'user_id', type: 'integer'), new OA\Property(property: 'year_month', type: 'string'), new OA\Property(property: 'work_style_id', type: 'integer')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
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

    /**
     * 指示書 13章: 個別の働き方指定を取り消し、「会社のデフォルトを使用」の状態に戻す。
     */
    #[OA\Delete(
        path: '/user-work-style-monthly-assignments/{userWorkStyleMonthlyAssignment}',
        operationId: 'userWorkStyleMonthlyAssignments.destroy',
        summary: '月次勤務形態割当を削除する',
        tags: ['月次勤務形態割当'],
        parameters: [new OA\Parameter(name: 'userWorkStyleMonthlyAssignment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 204, description: 'No Content'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function destroy(Request $request, UserWorkStyleMonthlyAssignment $userWorkStyleMonthlyAssignment, CommandBus $commandBus): Response
    {
        $commandBus->dispatch(new RemoveUserWorkStyleMonthlyAssignment(
            assignmentId: $userWorkStyleMonthlyAssignment->id,
            removedByUserId: $request->user()->id,
        ));

        return response()->noContent();
    }
}
