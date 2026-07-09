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

/**
 * UC-B002/UC-B003: 担当者割当・処理ステータス更新。
 */
class BackOfficeTaskController extends Controller
{
    public function indexUnassigned(): AnonymousResourceCollection
    {
        $tasks = BackOfficeTask::query()
            ->with('assignee')
            ->whereNull('assigned_user_id')
            ->latest()
            ->paginate(20);

        return BackOfficeTaskResource::collection($tasks);
    }

    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $tasks = BackOfficeTask::query()
            ->with('assignee')
            ->where('assigned_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return BackOfficeTaskResource::collection($tasks);
    }

    public function show(BackOfficeTask $backOfficeTask): BackOfficeTaskResource
    {
        return new BackOfficeTaskResource($backOfficeTask->load('assignee'));
    }

    public function assign(Request $request, BackOfficeTask $backOfficeTask, CommandBus $commandBus): BackOfficeTaskResource
    {
        $data = $request->validate(['assigned_user_id' => ['required', 'integer', 'exists:users,id']]);

        $commandBus->dispatch(new AssignBackOfficeTask(
            backOfficeTaskId: $backOfficeTask->id,
            assignedUserId: $data['assigned_user_id'],
            assignedByUserId: $request->user()->id,
        ));

        return new BackOfficeTaskResource($backOfficeTask->refresh()->load('assignee'));
    }

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
