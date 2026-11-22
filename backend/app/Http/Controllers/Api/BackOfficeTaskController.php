<?php

namespace App\Http\Controllers\Api;

use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackOfficeTaskResource;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use App\Models\WorkflowRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * UC-B002/UC-B003: 担当者割当・処理ステータス更新。
 */
#[OA\Tag(name: 'バックオフィス処理', description: '承認後の事務処理タスク')]
class BackOfficeTaskController extends Controller
{
    #[OA\Get(
        path: '/backoffice-tasks',
        operationId: 'backofficeTasks.index',
        summary: 'バックオフィスタスクを検索する',
        tags: ['バックオフィス処理'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'assignee', in: 'query', required: false, description: '担当者区分(省略時all)', schema: new OA\Schema(type: 'string', enum: ['unassigned', 'me', 'others', 'all'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'ステータス(省略時all。openは完了・取消以外)', schema: new OA\Schema(type: 'string', enum: ['open', 'all', 'not_started', 'in_review', 'needs_fix', 'processing', 'ordered', 'payment_scheduled', 'shipped', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $this->validateListRequest($request);
        $search = $data['search'] ?? null;
        $assignee = $data['assignee'] ?? 'all';
        $status = $data['status'] ?? 'all';
        $userId = $request->user()->id;

        $tasks = $this->applyListSearch(BackOfficeTask::query(), $search)
            ->statusFilter($status)
            ->assigneeScope($assignee, $userId)
            ->with('assignee')
            ->latest()
            ->paginate($data['per_page'] ?? 20);

        return BackOfficeTaskResource::collection($tasks)->additional([
            'meta' => ['assignee_counts' => $this->assigneeCounts($search, $status, $userId)],
        ]);
    }

    #[OA\Get(
        path: '/backoffice-tasks/{backOfficeTask}',
        operationId: 'backofficeTasks.show',
        summary: 'バックオフィスタスク詳細を取得する',
        tags: ['バックオフィス処理'],
        parameters: [new OA\Parameter(name: 'backOfficeTask', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(BackOfficeTask $backOfficeTask): BackOfficeTaskResource
    {
        return $this->detailResource($backOfficeTask->load('assignee'));
    }

    #[OA\Post(
        path: '/backoffice-tasks/{backOfficeTask}/assign',
        operationId: 'backofficeTasks.assign',
        summary: 'バックオフィスタスク担当者を割り当てる',
        tags: ['バックオフィス処理'],
        parameters: [new OA\Parameter(name: 'backOfficeTask', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['assigned_user_id'], properties: [new OA\Property(property: 'assigned_user_id', type: 'string', format: 'uuid')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function assign(Request $request, BackOfficeTask $backOfficeTask, CommandBus $commandBus): BackOfficeTaskResource
    {
        $data = $request->validate(['assigned_user_id' => ['required', 'string', 'exists:users,id']]);

        $commandBus->dispatch(new AssignBackOfficeTask(
            backOfficeTaskId: $backOfficeTask->id,
            assignedUserId: $data['assigned_user_id'],
            assignedByUserId: $request->user()->id,
        ));

        return $this->detailResource($backOfficeTask->refresh()->load('assignee'));
    }

    #[OA\Post(
        path: '/backoffice-tasks/{backOfficeTask}/status',
        operationId: 'backofficeTasks.changeStatus',
        summary: 'バックオフィスタスクのステータスを更新する',
        tags: ['バックオフィス処理'],
        parameters: [new OA\Parameter(name: 'backOfficeTask', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'comment', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function changeStatus(Request $request, BackOfficeTask $backOfficeTask, CommandBus $commandBus): BackOfficeTaskResource
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'comment' => ['nullable', 'string'],
        ]);

        $commandBus->dispatch(new ChangeBackOfficeTaskStatus(
            backOfficeTaskId: $backOfficeTask->id,
            newStatus: $data['status'],
            changedByUserId: $request->user()->id,
            comment: $data['comment'] ?? null,
        ));

        return $this->detailResource($backOfficeTask->refresh()->load('assignee'));
    }

    #[OA\Post(
        path: '/backoffice-tasks/bulk-complete',
        operationId: 'backofficeTasks.bulkComplete',
        summary: '自分の担当タスクを一括で完了する',
        tags: ['バックオフィス処理'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['task_ids'], properties: [new OA\Property(property: 'task_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function bulkComplete(Request $request, CommandBus $commandBus): AnonymousResourceCollection
    {
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1', 'max:100'],
            'task_ids.*' => ['required', 'string', 'distinct', 'exists:backoffice_tasks,id'],
        ]);

        $taskIds = $data['task_ids'];

        DB::transaction(function () use ($request, $commandBus, $taskIds): void {
            $tasks = BackOfficeTask::query()
                ->whereIn('id', $taskIds)
                ->where('assigned_user_id', $request->user()->id)
                ->lockForUpdate()
                ->get();

            if ($tasks->count() !== count($taskIds)) {
                throw ValidationException::withMessages([
                    'task_ids' => ['自分に割り当てられていないタスクは一括完了できません。'],
                ]);
            }

            foreach ($tasks as $task) {
                if ($task->status === BackOfficeTaskStatus::COMPLETED) {
                    continue;
                }

                $commandBus->dispatch(new ChangeBackOfficeTaskStatus(
                    backOfficeTaskId: $task->id,
                    newStatus: BackOfficeTaskStatus::COMPLETED,
                    changedByUserId: $request->user()->id,
                    comment: '一覧から一括完了',
                ));
            }
        });

        return BackOfficeTaskResource::collection(
            BackOfficeTask::query()->with('assignee')->whereIn('id', $taskIds)->get()
        );
    }

    /**
     * 詳細系のレスポンス。allowed_next_statuses の判定に発生源(WorkflowRequestの申請種別)を読み込む。
     * 発生源はmorphのため無条件にrequestTypeをeager loadできない(ChangeBackOfficeTaskStatusHandler参照)。
     */
    private function detailResource(BackOfficeTask $task): BackOfficeTaskResource
    {
        $task->loadMorph('source', [WorkflowRequest::class => ['requestType']]);

        return (new BackOfficeTaskResource($task))->withAllowedNextStatuses();
    }

    /** @return array{search?: string|null, assignee?: string, status?: string, per_page?: int} */
    private function validateListRequest(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'assignee' => ['sometimes', 'string', Rule::in(['unassigned', 'me', 'others', 'all'])],
            'status' => ['sometimes', 'string', Rule::in(array_merge(['open', 'all'], BackOfficeTaskStatus::all()))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * 担当者区分ごとの件数。search・statusは適用し、assigneeは適用しない(タブの件数に使う)。
     *
     * @return array{unassigned: int, me: int, others: int, all: int}
     */
    private function assigneeCounts(?string $search, string $status, string $userId): array
    {
        $row = $this->applyListSearch(BackOfficeTask::query(), $search)
            ->statusFilter($status)
            ->selectRaw(
                'SUM(CASE WHEN assigned_user_id IS NULL THEN 1 ELSE 0 END) AS unassigned_count, '
                .'SUM(CASE WHEN assigned_user_id = ? THEN 1 ELSE 0 END) AS me_count, '
                .'SUM(CASE WHEN assigned_user_id IS NOT NULL AND assigned_user_id <> ? THEN 1 ELSE 0 END) AS others_count, '
                .'COUNT(*) AS all_count',
                [$userId, $userId],
            )
            ->first();

        return [
            'unassigned' => (int) $row->unassigned_count,
            'me' => (int) $row->me_count,
            'others' => (int) $row->others_count,
            'all' => (int) $row->all_count,
        ];
    }

    private function applyListSearch(Builder $query, ?string $search): Builder
    {
        if ($search === null || trim($search) === '') {
            return $query;
        }

        $keyword = '%'.trim($search).'%';

        return $query->where(function (Builder $query) use ($keyword): void {
            $query->where('title', 'like', $keyword)
                ->orWhere('task_type', 'like', $keyword)
                ->orWhere('assigned_department', 'like', $keyword)
                ->orWhereHas('assignee', fn (Builder $assignee) => $assignee->where('name', 'like', $keyword));
        });
    }
}
