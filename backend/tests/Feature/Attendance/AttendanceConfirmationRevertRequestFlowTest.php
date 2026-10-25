<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\BackOfficeTask;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use Database\Seeders\RequestTypeSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 勤怠確定取消依頼(attendance_confirmation_revert)の承認で、月次勤怠が即時に未提出へ戻る
 * 連鎖(WorkflowRequestApproved → Reactor → RevertApprovedAttendanceMonthByRequest)のシナリオ。
 */
class AttendanceConfirmationRevertRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = '打刻の修正が必要なため';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestTypeSeeder::class);
    }

    private function yearMonth(User $employee): string
    {
        return Carbon::today($employee->timezone)->format('Y-m');
    }

    private function submitMonth(User $employee, User $approver): AttendanceMonth
    {
        $yearMonth = $this->yearMonth($employee);

        $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $this->actingAs($employee)->postJson('/api/attendance/clock-out');
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        return AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();
    }

    private function submitAndApproveMonth(User $employee, User $approver): AttendanceMonth
    {
        $month = $this->submitMonth($employee, $approver);
        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        return $month->refresh();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function draftRequest(User $applicant, User $approver, array $formData, string $typeCode = 'attendance_confirmation_revert'): string
    {
        $response = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $typeCode,
            'title' => '申請',
            'form_data' => $formData,
            'approver_user_id' => $approver->id,
        ]);
        $response->assertCreated();

        return $response->json('id');
    }

    private function createSubmittedRevertRequest(User $employee, User $approver, string $targetYearMonth): string
    {
        $id = $this->draftRequest($employee, $approver, ['target_year_month' => $targetYearMonth, 'reason' => self::REASON]);
        $this->actingAs($employee)->postJson("/api/workflow-requests/{$id}/submit")
            ->assertOk()->assertJsonPath('status', 'submitted');

        return $id;
    }

    /**
     * @return array{events: int, notifications: int, tasks: int}
     */
    private function counts(): array
    {
        return [
            'events' => EloquentStoredEvent::query()->count(),
            'notifications' => DB::table('notifications')->count(),
            'tasks' => BackOfficeTask::query()->count(),
        ];
    }

    private function revertedEvents(): Collection
    {
        return EloquentStoredEvent::query()->where('event_class', 'attendance.month_confirmation_reverted')->get();
    }

    public function test_approving_the_request_reverts_the_month_immediately(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = $this->submitAndApproveMonth($employee, $approver);
        $dayId = AttendanceDay::query()->where('user_id', $employee->id)->firstOrFail()->id;
        $this->actingAs($employee)->getJson("/api/attendance/days/{$dayId}")->assertJsonPath('is_locked', true);

        $requestId = $this->createSubmittedRevertRequest($employee, $approver, $month->year_month);
        $tasksBefore = BackOfficeTask::query()->count();

        $this->actingAs($approver)->postJson("/api/workflow-requests/{$requestId}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $month->refresh();
        $this->assertSame('not_submitted', $month->status);
        $this->assertSame(self::REASON, $month->revert_comment);
        $this->actingAs($employee)->getJson("/api/attendance/days/{$dayId}")->assertJsonPath('is_locked', false);
        $this->assertSame($tasksBefore, BackOfficeTask::query()->count());
        $this->assertSame('approved', WorkflowRequest::query()->findOrFail($requestId)->status);

        $events = $this->revertedEvents();
        $this->assertCount(1, $events);
        $this->assertSame($requestId, $events->first()->event_properties['workflowRequestId']);
        $this->assertSame($approver->id, $events->first()->event_properties['revertedByUserId']);
        $this->assertSame(1, DB::table('notifications')
            ->where('title', '月次勤怠が未提出に戻されました')->where('recipient_user_id', $employee->id)->count());
    }

    public function test_approval_fails_and_changes_nothing_when_the_month_is_submitted(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = $this->submitMonth($employee, $approver);

        $requestId = $this->createSubmittedRevertRequest($employee, $approver, $month->year_month);
        $before = $this->counts();

        $this->actingAs($approver)->postJson("/api/workflow-requests/{$requestId}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "対象の月次勤怠({$month->year_month})は承認待ちのため、確定を取り消せません。承認者に差戻しを依頼してください。"]);

        $this->assertSame('submitted', $month->refresh()->status);
        $this->assertFailedApprovalChangedNothing($requestId, $before);
    }

    public function test_approval_fails_and_changes_nothing_when_the_month_is_closed(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = $this->submitAndApproveMonth($employee, $approver);
        $this->actingAs($this->admin())->postJson("/api/attendance-months/{$month->id}/close")->assertOk();

        $requestId = $this->createSubmittedRevertRequest($employee, $approver, $month->year_month);
        $before = $this->counts();

        $this->actingAs($approver)->postJson("/api/workflow-requests/{$requestId}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "対象の月次勤怠({$month->year_month})は締め済みです。先に管理者による締め取消が必要です。"]);

        $this->assertSame('closed', $month->refresh()->status);
        $this->assertFailedApprovalChangedNothing($requestId, $before);
    }

    public function test_approval_fails_and_changes_nothing_when_the_month_does_not_exist(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $requestId = $this->createSubmittedRevertRequest($employee, $approver, '2020-01');
        $before = $this->counts();

        $this->actingAs($approver)->postJson("/api/workflow-requests/{$requestId}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => '対象の月次勤怠(2020-01)が見つかりません。']);

        $this->assertSame(0, AttendanceMonth::query()->count());
        $this->assertFailedApprovalChangedNothing($requestId, $before);
    }

    public function test_approval_fails_and_changes_nothing_when_the_target_month_format_is_invalid(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->submitAndApproveMonth($employee, $approver);

        $requestId = $this->createSubmittedRevertRequest($employee, $approver, '2026/09');
        $before = $this->counts();

        $this->actingAs($approver)->postJson("/api/workflow-requests/{$requestId}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => '対象の月次勤怠(2026/09)が見つかりません。']);

        $this->assertSame('approved', AttendanceMonth::query()->where('user_id', $employee->id)->firstOrFail()->status);
        $this->assertFailedApprovalChangedNothing($requestId, $before);
    }

    /**
     * @param  array{events: int, notifications: int, tasks: int}  $before
     */
    private function assertFailedApprovalChangedNothing(string $requestId, array $before): void
    {
        $this->assertSame('submitted', WorkflowRequest::query()->findOrFail($requestId)->status);
        $this->assertSame($before, $this->counts());
        $this->assertCount(0, $this->revertedEvents());
    }

    public function test_approval_succeeds_without_a_new_event_when_the_month_is_already_not_submitted(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::BACKOFFICE_STAFF, 'name' => 'バックオフィス担当']));
        $month = $this->submitAndApproveMonth($employee, $approver);

        $requestId = $this->createSubmittedRevertRequest($employee, $approver, $month->year_month);

        // 承認待ちの間に担当者が直接取り消す。
        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '直接取消',
        ])->assertOk();
        $this->assertCount(1, $this->revertedEvents());

        $this->actingAs($approver)->postJson("/api/workflow-requests/{$requestId}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $month->refresh();
        $this->assertSame('not_submitted', $month->status);
        $this->assertSame('直接取消', $month->revert_comment);
        $this->assertCount(1, $this->revertedEvents());
    }

    public function test_self_approved_request_reverts_the_month_at_submission(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = $this->submitAndApproveMonth($employee, $approver);

        $id = $this->draftRequest($employee, $employee, ['target_year_month' => $month->year_month, 'reason' => self::REASON]);
        $this->actingAs($employee)->postJson("/api/workflow-requests/{$id}/submit")
            ->assertOk()->assertJsonPath('status', 'approved');

        $month->refresh();
        $this->assertSame('not_submitted', $month->status);
        $this->assertSame(self::REASON, $month->revert_comment);
        $this->assertSame($employee->id, $this->revertedEvents()->first()->event_properties['revertedByUserId']);
    }

    public function test_self_approved_request_fails_at_submission_when_the_month_is_closed(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = $this->submitAndApproveMonth($employee, $approver);
        $this->actingAs($this->admin())->postJson("/api/attendance-months/{$month->id}/close")->assertOk();

        $id = $this->draftRequest($employee, $employee, ['target_year_month' => $month->year_month, 'reason' => self::REASON]);
        $before = $this->counts();

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$id}/submit")->assertStatus(422);

        $this->assertSame('closed', $month->refresh()->status);
        $this->assertSame('draft', WorkflowRequest::query()->findOrFail($id)->status);
        $this->assertSame($before, $this->counts());
    }

    public function test_approving_another_request_type_does_not_touch_attendance(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = $this->submitAndApproveMonth($employee, $approver);

        $id = $this->draftRequest($employee, $approver, ['detail' => 'その他'], 'general_request');
        $this->actingAs($employee)->postJson("/api/workflow-requests/{$id}/submit")->assertOk();
        $this->actingAs($approver)->postJson("/api/workflow-requests/{$id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame('approved', $month->refresh()->status);
        $this->assertNull($month->revert_comment);
        $this->assertCount(0, $this->revertedEvents());
    }
}
