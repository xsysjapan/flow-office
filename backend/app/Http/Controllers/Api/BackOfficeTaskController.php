<?php

namespace App\Http\Controllers\Api;

use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackOfficeTaskResource;
use App\Models\BackOfficeTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
    public function indexUnassigned(): AnonymousResourceCollection
    {
        $tasks = BackOfficeTask::query()
            ->with('assignee')
            ->whereNull('assigned_user_id')
            ->latest()
            ->paginate(20);

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
        $tasks = BackOfficeTask::query()
            ->with('assignee')
            ->where('assigned_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

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
}
