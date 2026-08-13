<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\ApplyCalendarBulkOperation;
use App\Domain\Attendance\Commands\RevertCalendarBulkOperation;
use App\Domain\Attendance\Services\CalendarBulkOperationPlanner;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarBulkOperationResource;
use App\Models\CalendarBulkOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-C013: 複数従業員予定の一括操作(プレビュー→確定適用→取消)。
 */
#[OA\Tag(name: '一括操作', description: '複数従業員予定の一括操作')]
class CalendarBulkOperationController extends Controller
{
    #[OA\Post(
        path: '/calendar-bulk-operations/preview',
        operationId: 'calendarBulkOperations.preview',
        summary: '一括操作の適用内容をプレビューする(保存しない)',
        tags: ['一括操作'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function preview(Request $request, CalendarBulkOperationPlanner $planner): JsonResponse
    {
        $data = $this->validateRequest($request);

        $plan = $planner->plan($data['operation_type'], $data['target_scope'], $data['conflict_policy']);

        return response()->json($plan);
    }

    #[OA\Post(
        path: '/calendar-bulk-operations',
        operationId: 'calendarBulkOperations.store',
        summary: '一括操作を確定適用する',
        tags: ['一括操作'],
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $this->validateRequest($request);

        $bulkOperation = $commandBus->dispatch(new ApplyCalendarBulkOperation(
            operationType: $data['operation_type'],
            targetScope: $data['target_scope'],
            conflictPolicy: $data['conflict_policy'],
            reason: $data['reason'],
            requestedByUserId: $request->user()->id,
        ));

        return (new CalendarBulkOperationResource($bulkOperation->load('targets')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/calendar-bulk-operations',
        operationId: 'calendarBulkOperations.index',
        summary: '一括操作の一覧を取得する',
        tags: ['一括操作'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(): AnonymousResourceCollection
    {
        return CalendarBulkOperationResource::collection(
            CalendarBulkOperation::query()->orderByDesc('created_at')->get(),
        );
    }

    #[OA\Get(
        path: '/calendar-bulk-operations/{calendarBulkOperation}',
        operationId: 'calendarBulkOperations.show',
        summary: '一括操作の詳細を取得する',
        tags: ['一括操作'],
        parameters: [new OA\Parameter(name: 'calendarBulkOperation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(CalendarBulkOperation $calendarBulkOperation): CalendarBulkOperationResource
    {
        return new CalendarBulkOperationResource($calendarBulkOperation->load('targets'));
    }

    #[OA\Post(
        path: '/calendar-bulk-operations/{calendarBulkOperation}/revert',
        operationId: 'calendarBulkOperations.revert',
        summary: '一括操作を取消す',
        tags: ['一括操作'],
        parameters: [new OA\Parameter(name: 'calendarBulkOperation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function revert(Request $request, CalendarBulkOperation $calendarBulkOperation, CommandBus $commandBus): CalendarBulkOperationResource
    {
        $bulkOperation = $commandBus->dispatch(new RevertCalendarBulkOperation(
            calendarBulkOperationId: $calendarBulkOperation->id,
            revertedByUserId: $request->user()->id,
        ));

        return new CalendarBulkOperationResource($bulkOperation->load('targets'));
    }

    /**
     * @return array{operation_type: string, target_scope: array<string, mixed>, conflict_policy: string, reason: string}
     */
    private function validateRequest(Request $request): array
    {
        $data = $request->validate([
            'operation_type' => ['required', 'string', Rule::in([
                CalendarBulkOperation::OPERATION_CALENDAR_APPLY,
                CalendarBulkOperation::OPERATION_ROTATION_GENERATE,
                CalendarBulkOperation::OPERATION_BULK_EDIT,
            ])],
            'target_scope' => ['required', 'array'],
            'conflict_policy' => ['required', 'string', Rule::in([
                ApplyCalendarBulkOperation::CONFLICT_POLICY_SKIP_EXISTING,
                ApplyCalendarBulkOperation::CONFLICT_POLICY_OVERWRITE,
                ApplyCalendarBulkOperation::CONFLICT_POLICY_FAIL_ON_CONFLICT,
            ])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (in_array($data['operation_type'], [CalendarBulkOperation::OPERATION_CALENDAR_APPLY, CalendarBulkOperation::OPERATION_ROTATION_GENERATE], true)) {
            $request->validate([
                'target_scope.user_ids' => ['required', 'array', 'min:1'],
                'target_scope.user_ids.*' => ['string', 'exists:users,id'],
                'target_scope.from' => ['required', 'date'],
                'target_scope.to' => ['required', 'date', 'after_or_equal:target_scope.from'],
            ]);

            if ($data['operation_type'] === CalendarBulkOperation::OPERATION_CALENDAR_APPLY) {
                $request->validate([
                    'target_scope.work_style_id' => ['required', 'string', 'exists:work_styles,id'],
                ]);
            }
        } else {
            $request->validate([
                'target_scope.entries' => ['required', 'array', 'min:1'],
                'target_scope.entries.*.user_id' => ['required', 'string', 'exists:users,id'],
                'target_scope.entries.*.work_date' => ['required', 'date'],
                'target_scope.entries.*.schedule_state' => ['required', 'string', Rule::in(['WORK', 'OFF', 'LEAVE'])],
                'target_scope.entries.*.entry_type' => ['nullable', 'string'],
                'target_scope.entries.*.work_style_id' => ['nullable', 'string', 'exists:work_styles,id'],
            ]);
        }

        return $data;
    }
}
