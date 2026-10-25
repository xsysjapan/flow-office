<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 締め済み・確定済み勤怠を修正するための救済コマンド。
 * - ReopenClosedAttendanceMonth (管理者専用): closed → approved
 * - RevertApprovedAttendanceMonth (バックオフィス担当者専用): approved → not_submitted
 */
class AttendanceMonthRescueCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function submitAndApprove(User $employee, User $approver): AttendanceMonth
    {
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $this->actingAs($employee)->postJson('/api/attendance/clock-out');

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $month = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();

        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        return $month->refresh();
    }

    public function test_admin_can_reopen_a_closed_month(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $month = $this->submitAndApprove($employee, $approver);

        $this->actingAs($admin)->postJson("/api/attendance-months/{$month->id}/close")
            ->assertOk()->assertJsonPath('status', 'closed');

        $this->actingAs($admin)->postJson("/api/attendance-months/{$month->id}/reopen", [
            'reason' => '打刻の記載漏れが見つかったため締めを取り消す',
        ])->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame('approved', $month->refresh()->status);
        $this->assertNull($month->refresh()->closed_at);
    }

    public function test_reopen_is_forbidden_for_non_admin(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $month = $this->submitAndApprove($employee, $approver);
        $this->actingAs($admin)->postJson("/api/attendance-months/{$month->id}/close")->assertOk();

        // approver自身(管理者ロールなし)は締め取消できない。
        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/reopen", [
            'reason' => '権限のないユーザーによる取消(拒否されるべき)',
        ])->assertStatus(403);
    }

    public function test_reopen_fails_when_month_is_not_closed(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $month = $this->submitAndApprove($employee, $approver);

        // まだ締めていない(approved)状態でreopenは呼べない。
        $this->actingAs($admin)->postJson("/api/attendance-months/{$month->id}/reopen", [
            'reason' => 'まだ締めていない月',
        ])->assertStatus(422);
    }

    public function test_backoffice_staff_can_revert_an_approved_month_confirmation(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $backofficeStaff = User::factory()->create();
        $this->assignRole($backofficeStaff, Role::query()->create(['code' => Role::BACKOFFICE_STAFF, 'name' => 'バックオフィス担当']));

        $month = $this->submitAndApprove($employee, $approver);

        $workflowRequest = WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'title' => '勤怠確定取消依頼',
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::APPROVED,
            'form_data' => ['target_year_month' => $month->year_month, 'reason' => '打刻修正のため'],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAs($backofficeStaff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '打刻修正のため確定を取り消す',
            'workflow_request_id' => $workflowRequest->id,
        ])->assertOk()->assertJsonPath('status', 'not_submitted');

        $month->refresh();
        $this->assertSame('not_submitted', $month->status);
        $this->assertNull($month->approved_at);
        $this->assertNull($month->submitted_at);
    }

    /**
     * 確定取消(approved → not_submitted)の後は、月次のロックが解除され、日次勤怠を通常の編集で直せる
     * (attendance_days.locked_atは廃止し、編集可否は月次の状態とロック表で判定する)。
     */
    public function test_a_day_can_be_edited_again_after_the_month_confirmation_is_reverted(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $backofficeStaff = User::factory()->create();
        $this->assignRole($backofficeStaff, Role::query()->create(['code' => Role::BACKOFFICE_STAFF, 'name' => 'バックオフィス担当']));

        $month = $this->submitAndApprove($employee, $approver);
        $dayId = AttendanceDay::query()->where('user_id', $employee->id)->firstOrFail()->id;

        // 確定済みの間は編集できない。
        $this->actingAs($employee)->getJson("/api/attendance/days/{$dayId}")->assertJsonPath('is_locked', true);
        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", ['reason' => '確定中の編集(拒否されるべき)'])
            ->assertStatus(422);

        $workflowRequest = WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'title' => '勤怠確定取消依頼',
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::APPROVED,
            'form_data' => ['target_year_month' => $month->year_month, 'reason' => '打刻修正のため'],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);
        $this->actingAs($backofficeStaff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '打刻修正のため確定を取り消す',
            'workflow_request_id' => $workflowRequest->id,
        ])->assertOk();

        $this->actingAs($employee)->getJson("/api/attendance/days/{$dayId}")->assertJsonPath('is_locked', false);
        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", ['reason' => '確定取消後の編集'])
            ->assertSuccessful();
    }

    public function test_revert_confirmation_is_forbidden_for_non_backoffice_staff(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $month = $this->submitAndApprove($employee, $approver);

        $workflowRequest = WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'title' => '勤怠確定取消依頼',
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::APPROVED,
            'form_data' => ['target_year_month' => $month->year_month, 'reason' => '打刻修正のため'],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        // 承認者自身(バックオフィス担当ロールなし)は確定取消できない。
        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '権限のないユーザーによる取消(拒否されるべき)',
            'workflow_request_id' => $workflowRequest->id,
        ])->assertStatus(403);
    }

    public function test_revert_confirmation_fails_when_month_is_not_approved(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $backofficeStaff = User::factory()->create();
        $this->assignRole($backofficeStaff, Role::query()->create(['code' => Role::BACKOFFICE_STAFF, 'name' => 'バックオフィス担当']));

        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');
        $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $this->actingAs($employee)->postJson('/api/attendance/clock-out');
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $month = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();

        $workflowRequest = WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'title' => '勤怠確定取消依頼',
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::APPROVED,
            'form_data' => ['target_year_month' => $yearMonth, 'reason' => '打刻修正のため'],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        // まだ承認されていない(submitted)状態ではrevert-confirmationは呼べない。
        $this->actingAs($backofficeStaff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => 'まだ承認されていない月',
            'workflow_request_id' => $workflowRequest->id,
        ])->assertStatus(422);
    }

    private const REVERT_NOTIFICATION_TITLE = '月次勤怠が未提出に戻されました';

    private function backofficeStaff(): User
    {
        $user = User::factory()->create();
        $this->assignRole($user, Role::query()->create(['code' => Role::BACKOFFICE_STAFF, 'name' => 'バックオフィス担当']));

        return $user;
    }

    private function revertedEvents(): Collection
    {
        return EloquentStoredEvent::query()->where('event_class', 'attendance.month_confirmation_reverted')->get();
    }

    public function test_revert_without_a_workflow_request_resets_the_month_and_unlocks_days(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $staff = $this->backofficeStaff();
        $month = $this->submitAndApprove($employee, $approver);
        $dayId = AttendanceDay::query()->where('user_id', $employee->id)->firstOrFail()->id;

        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '打刻の記載漏れを直してください',
        ])->assertOk()->assertJsonPath('status', 'not_submitted')
            ->assertJsonPath('revert_comment', '打刻の記載漏れを直してください');

        $month->refresh();
        $this->assertSame('not_submitted', $month->status);
        $this->assertSame('打刻の記載漏れを直してください', $month->revert_comment);
        $this->actingAs($employee)->getJson("/api/attendance/days/{$dayId}")->assertJsonPath('is_locked', false);
        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", ['reason' => '取消後の編集'])->assertSuccessful();

        $events = $this->revertedEvents();
        $this->assertCount(1, $events);
        $this->assertNull($events->first()->event_properties['workflowRequestId']);
    }

    public function test_resubmitting_after_a_revert_clears_revert_comment(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $staff = $this->backofficeStaff();
        $month = $this->submitAndApprove($employee, $approver);

        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '修正してください',
        ])->assertOk();
        $this->assertSame('修正してください', $month->refresh()->revert_comment);

        $this->actingAs($employee)->postJson("/api/attendance/months/{$month->year_month}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $month->refresh();
        $this->assertSame('submitted', $month->status);
        $this->assertNull($month->revert_comment);
    }

    public function test_revert_notifies_the_month_owner_once_with_or_without_a_workflow_request(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $staff = $this->backofficeStaff();
        $month = $this->submitAndApprove($employee, $approver);

        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '直接取消の理由',
        ])->assertOk();

        $rows = DB::table('notifications')->where('title', self::REVERT_NOTIFICATION_TITLE)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($employee->id, $rows->first()->recipient_user_id);
        $this->assertSame("{$month->year_month}の月次勤怠が未提出に戻されました。理由: 直接取消の理由", $rows->first()->summary);
        $this->assertStringEndsWith("/attendance/months/{$month->year_month}", $rows->first()->detail_url);

        // 申請ありの経路でも同じ通知が1件送られる。
        $this->actingAs($employee)->postJson("/api/attendance/months/{$month->year_month}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/approve")->assertOk();
        $workflowRequest = WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'title' => '勤怠確定取消依頼',
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::APPROVED,
            'form_data' => ['target_year_month' => $month->year_month, 'reason' => '打刻修正のため'],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);
        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '申請ありの理由',
            'workflow_request_id' => $workflowRequest->id,
        ])->assertOk();

        $this->assertSame(2, DB::table('notifications')->where('title', self::REVERT_NOTIFICATION_TITLE)->count());
        $this->assertSame($workflowRequest->id, $this->revertedEvents()->last()->event_properties['workflowRequestId']);
    }

    public function test_staff_can_revert_their_own_month(): void
    {
        $staff = $this->backofficeStaff();
        $approver = User::factory()->create();
        $month = $this->submitAndApprove($staff, $approver);

        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '自分の月を戻す',
        ])->assertOk()->assertJsonPath('status', 'not_submitted');
    }

    public function test_revert_validates_workflow_request_id_and_reason(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $staff = $this->backofficeStaff();
        $month = $this->submitAndApprove($employee, $approver);

        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '存在しない申請',
            'workflow_request_id' => (string) Str::uuid(),
        ])->assertStatus(422);

        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [])
            ->assertStatus(422);

        $this->assertSame('approved', $month->refresh()->status);
    }

    public function test_revert_comment_is_reproduced_by_replaying_the_projector(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $staff = $this->backofficeStaff();
        $month = $this->submitAndApprove($employee, $approver);

        $this->actingAs($staff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '再生で同じになる理由',
        ])->assertOk();

        Artisan::call('projections:rebuild', ['projector' => 'AttendanceMonthProjector']);

        $month = AttendanceMonth::query()->findOrFail($month->id);
        $this->assertSame('not_submitted', $month->status);
        $this->assertSame('再生で同じになる理由', $month->revert_comment);
    }
}
