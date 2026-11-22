<?php

namespace Tests\Feature\BackOffice;

use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\EventSourcing\CommandBus;
use App\Models\BackOfficeTask;
use App\Models\Notification;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * UC-B002: 担当者の設定・変更。未着手の割当は確認中へ進め(status_changed)、
 * 担当者の変更はステータスを変えない(assignedのみ)。新旧担当者へ通知する。
 */
class BackOfficeTaskAssignTest extends TestCase
{
    use RefreshDatabase;

    /** 申請種別の遷移マスタ(既定)。not_started→in_review を許可する。 */
    private const DEFAULT_TRANSITIONS = [
        'not_started' => ['in_review', 'processing', 'completed'],
        'in_review' => ['processing', 'completed'],
        'processing' => ['completed'],
    ];

    private function makeStaff(): User
    {
        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        return $staff;
    }

    /**
     * 状態はCommand経由で作る(承認由来のタスク作成 → 必要に応じて状態変更)。
     *
     * @param  array<string, array<int, string>>  $transitions
     */
    private function makeTask(array $transitions = self::DEFAULT_TRANSITIONS): BackOfficeTask
    {
        $applicant = User::factory()->create();
        $requestType = RequestType::query()->create([
            'code' => 'business_card', 'name' => '名刺申請', 'form_schema' => [], 'is_active' => true,
            'requires_backoffice_task' => true, 'backoffice_task_type' => 'business_card',
            'allowed_status_transitions' => $transitions,
        ]);
        $workflowRequest = WorkflowRequest::query()->create([
            'request_type_id' => $requestType->id, 'title' => 'タクシー代', 'applicant_user_id' => $applicant->id,
            'status' => 'approved', 'form_data' => ['amount' => 1000],
        ]);

        $this->bus()->dispatch(new CreateBackOfficeTaskFromApproval($workflowRequest->id));

        return BackOfficeTask::query()->where('source_id', $workflowRequest->id)->firstOrFail();
    }

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function setAssignee(BackOfficeTask $task, User $assignee, User $by): void
    {
        $this->bus()->dispatch(new AssignBackOfficeTask(
            backOfficeTaskId: $task->id,
            assignedUserId: $assignee->id,
            assignedByUserId: $by->id,
        ));
    }

    private function changeStatus(BackOfficeTask $task, string $status, User $by): void
    {
        $this->bus()->dispatch(new ChangeBackOfficeTaskStatus(
            backOfficeTaskId: $task->id,
            newStatus: $status,
            changedByUserId: $by->id,
        ));
    }

    private function assignViaApi(User $actor, BackOfficeTask $task, User $assignee)
    {
        return $this->actingAs($actor)->postJson("/api/backoffice-tasks/{$task->id}/assign", [
            'assigned_user_id' => $assignee->id,
        ]);
    }

    private function latestVersion(BackOfficeTask $task): int
    {
        return (int) EloquentStoredEvent::query()->where('aggregate_uuid', $task->id)->max('aggregate_version');
    }

    /**
     * @return Collection<int, EloquentStoredEvent>
     */
    private function eventsSince(BackOfficeTask $task, int $version)
    {
        return EloquentStoredEvent::query()
            ->where('aggregate_uuid', $task->id)
            ->where('aggregate_version', '>', $version)
            ->orderBy('aggregate_version')
            ->get();
    }

    private function notificationsTo(User $recipient, string $title): int
    {
        return Notification::query()->where('recipient_user_id', $recipient->id)->where('title', $title)->count();
    }

    public function test_assigning_a_not_started_task_records_assigned_and_status_changed(): void
    {
        $staff = $this->makeStaff();
        $assignee = User::factory()->create();
        $task = $this->makeTask();
        $version = $this->latestVersion($task);

        $this->assignViaApi($staff, $task, $assignee)->assertOk()->assertJsonPath('status', 'in_review');

        $added = $this->eventsSince($task, $version);
        $this->assertSame(['backoffice_task.assigned', 'backoffice_task.status_changed'], $added->pluck('event_class')->all());

        $assigned = $added[0]->event_properties;
        $this->assertSame($assignee->id, $assigned['assignedUserId']);
        $this->assertSame($staff->id, $assigned['assignedByUserId']);
        $this->assertNull($assigned['previousAssignedUserId']);

        $statusChanged = $added[1]->event_properties;
        $this->assertSame('not_started', $statusChanged['previousStatus']);
        $this->assertSame('in_review', $statusChanged['newStatus']);
        $this->assertSame($staff->id, $statusChanged['changedByUserId']);

        $this->assertDatabaseHas('backoffice_tasks', [
            'id' => $task->id, 'status' => 'in_review', 'assigned_user_id' => $assignee->id,
        ]);
    }

    public function test_reassigning_a_processing_task_changes_only_the_assignee(): void
    {
        $staff = $this->makeStaff();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $task = $this->makeTask();
        $this->setAssignee($task, $first, $staff);
        $this->changeStatus($task, 'processing', $staff);
        $version = $this->latestVersion($task);

        $this->assignViaApi($staff, $task, $second)->assertOk()->assertJsonPath('status', 'processing');

        $added = $this->eventsSince($task, $version);
        $this->assertSame(['backoffice_task.assigned'], $added->pluck('event_class')->all());
        $this->assertSame($first->id, $added[0]->event_properties['previousAssignedUserId']);
        $this->assertSame($second->id, $added[0]->event_properties['assignedUserId']);

        $this->assertDatabaseHas('backoffice_tasks', [
            'id' => $task->id, 'status' => 'processing', 'assigned_user_id' => $second->id,
        ]);
    }

    public function test_assigning_an_unassigned_processing_task_keeps_its_status(): void
    {
        $staff = $this->makeStaff();
        $assignee = User::factory()->create();
        $task = $this->makeTask();
        $this->changeStatus($task, 'processing', $staff);
        $version = $this->latestVersion($task);

        $this->assignViaApi($staff, $task, $assignee)->assertOk()->assertJsonPath('status', 'processing');

        $added = $this->eventsSince($task, $version);
        $this->assertSame(['backoffice_task.assigned'], $added->pluck('event_class')->all());
        $this->assertNull($added[0]->event_properties['previousAssignedUserId']);

        $this->assertDatabaseHas('backoffice_tasks', [
            'id' => $task->id, 'status' => 'processing', 'assigned_user_id' => $assignee->id,
        ]);
    }

    public function test_assigning_keeps_not_started_when_the_request_type_does_not_allow_in_review(): void
    {
        $staff = $this->makeStaff();
        $assignee = User::factory()->create();
        $task = $this->makeTask([
            'not_started' => ['processing'],
            'in_review' => ['processing'],
        ]);
        $version = $this->latestVersion($task);

        $this->assignViaApi($staff, $task, $assignee)->assertOk()->assertJsonPath('status', 'not_started');

        $added = $this->eventsSince($task, $version);
        $this->assertSame(['backoffice_task.assigned'], $added->pluck('event_class')->all());

        $this->assertDatabaseHas('backoffice_tasks', [
            'id' => $task->id, 'status' => 'not_started', 'assigned_user_id' => $assignee->id,
        ]);
    }

    public function test_assigning_the_same_assignee_records_nothing_and_notifies_nobody(): void
    {
        $staff = $this->makeStaff();
        $assignee = User::factory()->create();
        $task = $this->makeTask();
        $this->setAssignee($task, $assignee, $staff);
        $version = $this->latestVersion($task);
        $notificationCount = Notification::query()->count();

        $this->assignViaApi($staff, $task, $assignee)->assertOk()->assertJsonPath('status', 'in_review');

        $this->assertSame($version, $this->latestVersion($task));
        $this->assertSame($notificationCount, Notification::query()->count());
    }

    public function test_completed_task_cannot_be_assigned_even_to_the_same_assignee(): void
    {
        $staff = $this->makeStaff();
        $assignee = User::factory()->create();
        $other = User::factory()->create();
        $task = $this->makeTask();
        $this->setAssignee($task, $assignee, $staff);
        $this->changeStatus($task, 'completed', $staff);
        $version = $this->latestVersion($task);

        $this->assignViaApi($staff, $task, $other)
            ->assertUnprocessable()
            ->assertJsonPath('message', '完了・取消済みのタスクは担当者を変更できません。');
        $this->assignViaApi($staff, $task, $assignee)
            ->assertUnprocessable()
            ->assertJsonPath('message', '完了・取消済みのタスクは担当者を変更できません。');

        $this->assertSame($version, $this->latestVersion($task));
        $this->assertDatabaseHas('backoffice_tasks', [
            'id' => $task->id, 'status' => 'completed', 'assigned_user_id' => $assignee->id,
        ]);
    }

    public function test_reassigning_notifies_the_new_and_the_previous_assignee(): void
    {
        $staff = $this->makeStaff();
        $first = User::factory()->create();
        $second = User::factory()->create(['name' => '新担当 太郎']);
        $task = $this->makeTask();
        $this->setAssignee($task, $first, $staff);
        $this->changeStatus($task, 'processing', $staff);

        $this->assignViaApi($staff, $task, $second)->assertOk();

        $this->assertSame(1, $this->notificationsTo($second, 'タスク割当'));
        $this->assertSame(1, $this->notificationsTo($first, 'タスク担当変更'));

        $notice = Notification::query()->where('recipient_user_id', $first->id)->where('title', 'タスク担当変更')->firstOrFail();
        $this->assertSame("「{$task->title}」の担当が新担当 太郎さんに変更されました。", $notice->summary);
    }

    public function test_previous_assignee_is_not_notified_when_the_actor_is_the_previous_assignee(): void
    {
        $staff = $this->makeStaff();
        $second = User::factory()->create();
        $task = $this->makeTask();
        $this->setAssignee($task, $staff, $staff);
        $this->changeStatus($task, 'processing', $staff);

        $this->assignViaApi($staff, $task, $second)->assertOk();

        $this->assertSame(1, $this->notificationsTo($second, 'タスク割当'));
        $this->assertSame(0, $this->notificationsTo($staff, 'タスク担当変更'));
    }
}
