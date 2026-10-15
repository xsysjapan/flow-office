<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Commands\ApproveSpecialLeaveRequest;
use App\Domain\SpecialLeave\Commands\ResubmitSpecialLeaveRequest;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\SpecialLeaveAccount\Commands\ConfirmSpecialLeaveUsage;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayLeave;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\Role;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\SpecialLeaveUsageAllocation;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 特別休暇のユースケース(申請・承認・差戻し・再提出・取消・管理者取消・まとめ申請・衝突・締め・残数不足・冪等)を
 * API/Commandから通し、各ステップの後で申請・ワークフロー・残数(付与・消化記録)・勤怠(休暇ビュー・勤怠日・日次計算)の
 * 状態を確認するシナリオテスト(UI非依存。原則16・domain-testスキル)。
 */
class SpecialLeaveScenarioTest extends TestCase
{
    use RefreshDatabase;
    use SpecialLeaveTestHelpers;

    private const DATE = '2026-08-10';

    /** 利用者の勤務予定(通常勤務)を対象日ぶん作る。 */
    private function workingDays(User $user, array $dates, int $prescribedDailyMinutes = 480): void
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$user->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        foreach ($dates as $date) {
            EmployeeCalendarEntry::query()->create([
                'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
                'planned_break_minutes' => 60,
            ]);
        }
    }

    private function createType(bool $requiresGrant = true): SpecialLeaveType
    {
        return SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true, 'requires_grant' => $requiresGrant]);
    }

    /** 特別休暇を申請し、申請ID(workflow経由のため申請の作成はReactorが行う)を返す。 */
    private function requestSpecialLeave(User $employee, User $approver, SpecialLeaveType $type, string $date, string $leaveType = 'full', ?string $groupId = null): string
    {
        $response = $this->actingAs($employee)->postJson('/api/special-leave/requests', array_filter([
            'special_leave_type_id' => $type->id,
            'target_date' => $date,
            'leave_type' => $leaveType,
            'approver_user_id' => $approver->id,
            'request_group_id' => $groupId,
        ], fn ($value) => $value !== null));

        $response->assertCreated();
        $this->assertSame('submitted', $response->json('status'));

        return (string) $response->json('id');
    }

    private function workflowRequestIdFor(string $requestId): string
    {
        return (string) WorkflowRequest::query()
            ->where('subject_type', 'special_leave_request')
            ->where('subject_id', $requestId)
            ->value('id');
    }

    private function approve(User $approver, string $requestId): void
    {
        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    private function remainingOf(SpecialLeaveGrant $grant): float
    {
        return (float) $grant->refresh()->remaining_days;
    }

    private function dayOf(User $employee, string $date = self::DATE): ?AttendanceDay
    {
        return AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $date)->first();
    }

    private function hrUser(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    /** 申請・承認・残数・勤怠の通常の流れ: 申請→承認で消化が確定し、勤怠は休暇ビューと日次計算に反映される。 */
    public function test_request_then_approve_consumes_the_grant_and_reflects_on_attendance(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);

        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);
        $workflowRequestId = $this->workflowRequestIdFor($requestId);

        // 申請時: 休暇ビューは申請中、勤怠日は休暇だけの日として作られ、残数はまだ消費されない(承認時に充当)。
        $this->assertSame('submitted', $this->specialLeaveOn($employee->id, self::DATE)?->request_status);
        $this->assertSame(WorkflowRequestStatus::SUBMITTED, WorkflowRequest::query()->findOrFail($workflowRequestId)->status);
        $this->assertSame('leave', $this->dayOf($employee)?->source);
        $this->assertSame(3.0, $this->remainingOf($grant));
        $this->assertSame(1, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());

        $this->approve($approver, $requestId);

        // 承認後: ワークフロー承認済み・消化確定・残数1日減・休暇ビューは承認済み・日次計算に特別休暇が入る。
        $this->assertSame(WorkflowRequestStatus::APPROVED, WorkflowRequest::query()->findOrFail($workflowRequestId)->status);
        $this->assertSame(2.0, $this->remainingOf($grant));
        $this->assertSame('approved', $this->specialLeaveOn($employee->id, self::DATE)?->request_status);
        $this->assertSame('full', $this->specialLeaveOn($employee->id, self::DATE)?->unit);
        $this->assertEquals(1.0, $this->dayOf($employee)?->calculation->special_leave_days);
        $usage = SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->firstOrFail();
        $this->assertTrue((bool) $usage->is_confirmed);
        $this->assertEquals(0.0, (float) $usage->unallocated_days);
    }

    /** 差戻し→再提出→承認: 差戻しで残数が戻り、再提出の承認でも二重計上されない。 */
    public function test_returned_request_restores_the_balance_and_resubmit_then_approve_does_not_double_count(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);
        $workflowRequestId = $this->workflowRequestIdFor($requestId);

        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/return", ['comment' => '日程を確認'])
            ->assertOk()
            ->assertJsonPath('status', 'returned');

        // 差戻し: 休暇ビューは差戻し、消化記録は取り消され、残数は戻る。
        $this->assertSame('returned', $this->specialLeaveOn($employee->id, self::DATE)?->request_status);
        $this->assertSame(0, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());
        $this->assertSame(3.0, $this->remainingOf($grant));

        // 申請者の「提出する」で再提出: 申請中に戻り、消化記録が1件だけ作られる。
        $this->actingAs($employee)->postJson("/api/workflow-requests/{$workflowRequestId}/submit")->assertSuccessful();
        $this->assertSame('submitted', SpecialLeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame('submitted', $this->specialLeaveOn($employee->id, self::DATE)?->request_status);
        $this->assertSame(1, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());
        $this->assertSame(3.0, $this->remainingOf($grant));

        $this->approve($approver, $requestId);

        // 承認: 残数は1日分だけ減る(差戻し前の消化と合算されない)。
        $this->assertSame(2.0, $this->remainingOf($grant));
        $this->assertSame(1, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());
        $this->assertSame('approved', $this->specialLeaveOn($employee->id, self::DATE)?->request_status);
    }

    /** 申請中の取消: 申請・ワークフロー・休暇ビュー・勤怠日(休暇だけの日)が取り消され、残数は変わらない。 */
    public function test_applicant_cancels_a_submitted_request_and_the_workflow_follows(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);
        $workflowRequestId = $this->workflowRequestIdFor($requestId);

        $this->actingAs($employee)->postJson("/api/special-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(WorkflowRequestStatus::CANCELLED, WorkflowRequest::query()->findOrFail($workflowRequestId)->status);
        $this->assertNull($this->activeSpecialLeaveOn($employee->id, self::DATE));
        $this->assertNull($this->dayOf($employee));
        $this->assertSame(0, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());
        $this->assertSame(3.0, $this->remainingOf($grant));
    }

    /** 承認済みの取消: 残数が戻り、勤怠日が消える。承認済みのワークフローは承認済みのまま(設計原則13)。 */
    public function test_applicant_cancels_an_approved_request_restores_the_balance_and_keeps_the_workflow_approved(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);
        $workflowRequestId = $this->workflowRequestIdFor($requestId);
        $this->approve($approver, $requestId);

        $this->actingAs($employee)->postJson("/api/special-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(WorkflowRequestStatus::APPROVED, WorkflowRequest::query()->findOrFail($workflowRequestId)->status);
        $this->assertSame(3.0, $this->remainingOf($grant));
        $this->assertNull($this->activeSpecialLeaveOn($employee->id, self::DATE));
        $this->assertNull($this->dayOf($employee));
        // 口座Projectorの結果: 取消された消化記録と充当は残らない(旧Projectorは書き換えない)。
        $this->assertSame(0, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());
        $this->assertSame(0, SpecialLeaveUsageAllocation::query()->count());
    }

    /** ワークフロー側からの取消(申請中): 休暇申請も取消になり、残数・勤怠は戻る。 */
    public function test_cancelling_the_workflow_cancels_the_special_leave_request(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);

        $this->actingAs($employee)->postJson('/api/workflow-requests/'.$this->workflowRequestIdFor($requestId).'/cancel', [
            'reason' => '取り下げ',
        ])->assertSuccessful();

        $this->assertSame('cancelled', SpecialLeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertNull($this->activeSpecialLeaveOn($employee->id, self::DATE));
        $this->assertSame(3.0, $this->remainingOf($grant));
    }

    /** 管理者による取消(承認済み): 申請者本人チェックなしで取り消され、残数が戻る。 */
    public function test_admin_cancel_of_an_approved_request_restores_the_balance(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $hr = $this->hrUser();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);
        $this->approve($approver, $requestId);

        $this->actingAs($hr)->postJson("/api/special-leave/requests/{$requestId}/admin-cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(3.0, $this->remainingOf($grant));
        $this->assertNull($this->dayOf($employee));
    }

    /** まとめ申請: 1日を承認すると、同じまとめ申請の申請中の兄弟も承認され、それぞれのワークフローも承認される。 */
    public function test_approving_one_day_of_a_group_request_approves_the_pending_siblings(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE, '2026-08-11']);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        $group = (string) Str::uuid();
        $first = $this->requestSpecialLeave($employee, $approver, $type, self::DATE, 'full', $group);
        $second = $this->requestSpecialLeave($employee, $approver, $type, '2026-08-11', 'full', $group);

        $this->approve($approver, $first);

        $this->assertSame('approved', SpecialLeaveRequest::query()->findOrFail($second)->status);
        $this->assertSame(WorkflowRequestStatus::APPROVED, WorkflowRequest::query()->findOrFail($this->workflowRequestIdFor($second))->status);
        $this->assertSame(1.0, $this->remainingOf($grant));
        $this->assertSame('approved', $this->specialLeaveOn($employee->id, '2026-08-11')?->request_status);
    }

    /** 有給と同日: 同じ日に有給の休暇がある場合、特別休暇の申請は拒否され、どの文脈の状態も変わらない。 */
    public function test_special_leave_on_a_day_with_paid_leave_is_rejected_and_nothing_changes(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));
        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => self::DATE,
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated();

        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => self::DATE,
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);

        $this->assertSame(0, SpecialLeaveRequest::query()->where('user_id', $employee->id)->count());
        $this->assertSame(0, WorkflowRequest::query()->where('subject_type', 'special_leave_request')->count());
        $this->assertSame(0, AttendanceDayLeave::query()->where('user_id', $employee->id)->where('leave_kind', AttendanceDayLeave::KIND_SPECIAL)->count());
        $this->assertSame(0, SpecialLeaveUsage::query()->where('user_id', $employee->id)->count());
        $this->assertSame(3.0, $this->remainingOf($grant));
    }

    /** 締め済みの日: 締めた月の休暇の申請・取消は拒否され、状態は変わらない。 */
    public function test_closed_month_rejects_a_new_request_and_a_cancellation(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE, '2026-08-12']);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);
        $this->approve($approver, $requestId);

        $monthApprover = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => $monthApprover->id,
        ])->assertSuccessful();

        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-12',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
        $this->actingAs($employee)->postJson("/api/special-leave/requests/{$requestId}/cancel")->assertStatus(422);

        $this->assertSame('approved', SpecialLeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame(2.0, $this->remainingOf($grant));
        $this->assertSame(1, SpecialLeaveRequest::query()->where('user_id', $employee->id)->count());
    }

    /** 残数不足: 残数を超えても承認され、充当できた分だけ充当し、不足量は未充当として記録される(論点17)。 */
    public function test_approval_succeeds_with_insufficient_balance_and_records_the_unallocated_days(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 0.5,
        ]);
        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);

        $this->approve($approver, $requestId);

        $this->assertSame(0.0, $this->remainingOf($grant));
        $usage = SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->firstOrFail();
        $this->assertTrue((bool) $usage->is_confirmed);
        $this->assertEquals(0.5, (float) $usage->unallocated_days);
        $this->assertSame('approved', $this->specialLeaveOn($employee->id, self::DATE)?->request_status);
    }

    /** 冪等: 同じ承認・確定・再提出のCommandを再度処理しても、どの文脈の状態も変わらない。 */
    public function test_replaying_the_reactor_commands_changes_nothing(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->workingDays($employee, [self::DATE]);
        $grant = $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);
        $requestId = $this->requestSpecialLeave($employee, $approver, $type, self::DATE);
        $this->approve($approver, $requestId);

        $commandBus = app(CommandBus::class);
        $commandBus->dispatch(new ApproveSpecialLeaveRequest($requestId, null, viaReactor: true));
        $commandBus->dispatch(new ConfirmSpecialLeaveUsage(
            userId: (string) $employee->id,
            requestId: $requestId,
            requiresGrant: true,
            viaReactor: true,
        ));
        $commandBus->dispatch(new ResubmitSpecialLeaveRequest($requestId, null, viaReactor: true));

        $this->assertSame('approved', SpecialLeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame(2.0, $this->remainingOf($grant));
        $this->assertSame(1, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());
        $this->assertSame(1, AttendanceDayLeave::query()->where('leave_kind', AttendanceDayLeave::KIND_SPECIAL)->count());
    }
}
