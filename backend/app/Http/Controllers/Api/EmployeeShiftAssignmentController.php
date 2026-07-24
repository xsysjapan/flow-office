<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\AssignShiftPatternDay;
use App\Domain\Attendance\Commands\EditEmployeeShiftAssignment;
use App\Domain\Attendance\Commands\GenerateEmployeeShiftAssignments;
use App\Domain\Attendance\Commands\PublishEmployeeShiftAssignments;
use App\Domain\Attendance\Services\ShiftScheduleReviewService;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeShiftAssignmentResource;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * 社員別勤務予定 (docs/19-implementation-phases.md Phase4)。
 * 会社カレンダーの日区分をベースにした一括生成(UC-C003)と、3交代制シフト表の
 * 日別パターン割当(UC-C004)の両方をここで扱う。
 */
#[OA\Tag(name: '勤務予定', description: '社員別勤務予定とシフト表')]
class EmployeeShiftAssignmentController extends Controller
{
    #[OA\Get(
        path: '/employee-shift-assignments',
        operationId: 'employeeShiftAssignments.index',
        summary: '社員別勤務予定を取得する',
        tags: ['勤務予定'],
        parameters: [new OA\Parameter(name: 'user_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        // 本人または管理者のみ閲覧できる(docs/25-usecases-integrations-mcp.md UC-I002、
        // 個人API/MCP連携からの`schedule:self:read`スコープでの利用を想定)。
        $this->abortUnlessOwnerOrAdmin($request, $data['user_id'], '他の社員の勤務予定を閲覧する権限がありません。');

        $assignments = EmployeeShiftAssignment::query()
            ->where('user_id', $data['user_id'])
            ->whereDate('work_date', '>=', $data['from'])
            ->whereDate('work_date', '<=', $data['to'])
            ->orderBy('work_date')
            ->get();

        return EmployeeShiftAssignmentResource::collection($assignments);
    }

    /**
     * UC-C003: work_stylesに紐づくカレンダーの日区分をもとに、指定期間の勤務予定を一括生成する。
     */
    #[OA\Post(
        path: '/employee-shift-assignments/generate',
        operationId: 'employeeShiftAssignments.generate',
        summary: '勤務予定を一括生成する',
        tags: ['勤務予定'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'work_style_id', 'from', 'to'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'work_style_id', type: 'string', format: 'uuid'), new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function generate(Request $request, CommandBus $commandBus): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'work_style_id' => ['required', 'string', 'exists:work_styles,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $assignments = $commandBus->dispatch(new GenerateEmployeeShiftAssignments(
            userId: $data['user_id'],
            workStyleId: $data['work_style_id'],
            from: $data['from'],
            to: $data['to'],
            generatedByUserId: $request->user()->id,
        ));

        return EmployeeShiftAssignmentResource::collection($assignments);
    }

    /**
     * UC-C004 手順3〜4: 3交代制シフト表で、社員の特定日にシフトパターンを割り当てる。
     */
    #[OA\Post(
        path: '/employee-shift-assignments/assign-pattern',
        operationId: 'employeeShiftAssignments.assignPattern',
        summary: 'シフトパターンを日別に割り当てる',
        tags: ['勤務予定'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'work_style_id', 'work_date', 'shift_pattern_id'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'work_style_id', type: 'string', format: 'uuid'), new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'shift_pattern_id', type: 'string', format: 'uuid'), new OA\Property(property: 'is_legal_holiday', type: 'boolean'), new OA\Property(property: 'is_company_holiday', type: 'boolean')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function assignPattern(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'work_style_id' => ['required', 'string', 'exists:work_styles,id'],
            'work_date' => ['required', 'date'],
            'shift_pattern_id' => ['required', 'string', 'exists:shift_patterns,id'],
            'is_legal_holiday' => ['boolean'],
            'is_company_holiday' => ['boolean'],
        ]);

        $assignment = $commandBus->dispatch(new AssignShiftPatternDay(
            userId: $data['user_id'],
            workDate: $data['work_date'],
            workStyleId: $data['work_style_id'],
            shiftPatternId: $data['shift_pattern_id'],
            isLegalHoliday: $data['is_legal_holiday'] ?? false,
            isCompanyHoliday: $data['is_company_holiday'] ?? false,
            assignedByUserId: $request->user()->id,
        ));

        return (new EmployeeShiftAssignmentResource($assignment))->response()->setStatusCode(201);
    }

    /**
     * UC-C004 手順5: 公開前に法定休日不足・連続勤務・月間予定時間を確認する(読み取り専用、警告のみ)。
     */
    #[OA\Get(
        path: '/employee-shift-assignments/review',
        operationId: 'employeeShiftAssignments.review',
        summary: '公開前の勤務予定を点検する',
        tags: ['勤務予定'],
        parameters: [new OA\Parameter(name: 'department', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'user_ids', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), style: 'form', explode: true), new OA\Parameter(name: 'year_month', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function review(Request $request, ShiftScheduleReviewService $reviewService): JsonResponse
    {
        $data = $request->validate([
            'department' => ['nullable', 'string'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['string', 'exists:users,id'],
            'year_month' => ['required', 'date_format:Y-m'],
        ]);

        $userIds = $this->resolveTargetUserIds($data);

        return response()->json($reviewService->review($userIds, $data['year_month']));
    }

    /**
     * UC-C004 手順6: 3交代制シフト表を公開する。
     */
    #[OA\Post(
        path: '/employee-shift-assignments/publish',
        operationId: 'employeeShiftAssignments.publish',
        summary: '勤務予定を公開する',
        tags: ['勤務予定'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year_month'], properties: [new OA\Property(property: 'department', type: 'string', nullable: true), new OA\Property(property: 'user_ids', type: 'array', nullable: true, items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'year_month', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function publish(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'department' => ['nullable', 'string'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['string', 'exists:users,id'],
            'year_month' => ['required', 'date_format:Y-m'],
        ]);

        $userIds = $this->resolveTargetUserIds($data);

        $result = $commandBus->dispatch(new PublishEmployeeShiftAssignments(
            userIds: $userIds,
            yearMonth: $data['year_month'],
            publishedByUserId: $request->user()->id,
        ));

        return response()->json($result);
    }

    /**
     * 勤務予定(所定労働時間)を編集する。1か月単位変形労働時間制で、特定の日だけ
     * あらかじめ8時間を超える所定労働時間を設定する場合などに使う。
     */
    #[OA\Put(
        path: '/employee-shift-assignments/{employeeShiftAssignment}',
        operationId: 'employeeShiftAssignments.update',
        summary: '勤務予定を編集する',
        tags: ['勤務予定'],
        parameters: [new OA\Parameter(name: 'employeeShiftAssignment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['planned_break_minutes', 'reason'], properties: [new OA\Property(property: 'planned_start_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'planned_end_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'planned_break_minutes', type: 'integer'), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function update(Request $request, EmployeeShiftAssignment $employeeShiftAssignment, CommandBus $commandBus): EmployeeShiftAssignmentResource
    {
        $data = $request->validate([
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date'],
            'planned_break_minutes' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string'],
        ]);

        $commandBus->dispatch(new EditEmployeeShiftAssignment(
            employeeShiftAssignmentId: $employeeShiftAssignment->id,
            plannedStartAt: $data['planned_start_at'] ?? null,
            plannedEndAt: $data['planned_end_at'] ?? null,
            plannedBreakMinutes: $data['planned_break_minutes'],
            reason: $data['reason'],
            editedByUserId: $request->user()->id,
        ));

        return new EmployeeShiftAssignmentResource($employeeShiftAssignment->refresh());
    }

    /**
     * @param  array{department?: ?string, user_ids?: ?list<string>}  $data
     * @return list<string>
     */
    private function resolveTargetUserIds(array $data): array
    {
        $userIds = collect($data['user_ids'] ?? []);

        if (! empty($data['department'])) {
            $userIds = $userIds->merge(
                User::query()->where('department', $data['department'])->pluck('id')
            );
        }

        return $userIds->unique()->values()->all();
    }
}
