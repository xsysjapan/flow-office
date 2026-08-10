<?php

namespace App\Http\Controllers\Api;

use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackOfficeTaskResource;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * UC-B002/UC-B003: 担当者割当・処理ステータス更新。
 */
#[OA\Tag(name: 'バックオフィス処理', description: '承認後の事務処理タスク')]
class BackOfficeTaskController extends Controller
{
    #[OA\Get(
        path: '/backoffice-tasks/unassigned',
        operationId: 'backofficeTasks.unassigned',
        summary: '未割当タスク一覧を取得する',
        tags: ['バックオフィス処理'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexUnassigned(Request $request): AnonymousResourceCollection
    {
        $data = $this->validateListRequest($request);
        $tasks = $this->applyListSearch(BackOfficeTask::query(), $data['search'] ?? null)
            ->with('assignee')
            ->whereNull('assigned_user_id')
            ->latest()
            ->paginate($data['per_page'] ?? 20);

        return BackOfficeTaskResource::collection($tasks);
    }

    #[OA\Get(
        path: '/backoffice-tasks/mine',
        operationId: 'backofficeTasks.mine',
        summary: '自分の担当タスク一覧を取得する',
        tags: ['バックオフィス処理'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $data = $this->validateListRequest($request);
        $tasks = $this->applyListSearch(BackOfficeTask::query(), $data['search'] ?? null)
            ->with('assignee')
            ->where('assigned_user_id', $request->user()->id)
            ->latest()
            ->paginate($data['per_page'] ?? 20);

        return BackOfficeTaskResource::collection($tasks);
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
        return new BackOfficeTaskResource($backOfficeTask->load('assignee'));
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

        return new BackOfficeTaskResource($backOfficeTask->refresh()->load('assignee'));
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

        return new BackOfficeTaskResource($backOfficeTask->refresh()->load('assignee'));
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

    /** @return array{search?: string, per_page?: int} */
    private function validateListRequest(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
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
