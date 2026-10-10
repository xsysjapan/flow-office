<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveUsage;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 代休のシナリオテスト(UI非依存。API操作から始まる連鎖を通し、各文脈の状態を確認する。domain-testスキル)。
 * 文脈: 申請・承認(ワークフロー)・代休申請・代休口座(残数・消化記録)・勤怠(休暇ビュー・日次計算の対象日)。
 */
class CompensatoryLeaveScenarioTest extends TestCase
{
    use CompensatoryLeaveTestHelpers;
    use RefreshDatabase;

    private function hrStaff(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    private function workflowIdOf(string $requestId): string
    {
        return WorkflowRequest::query()->where('subject_id', $requestId)->value('id');
    }

    private function assertLeaveRow(User $employee, string $date, string $status): void
    {
        $this->assertDatabaseHas('attendance_day_leaves', [
            'user_id' => $employee->id,
            'work_date' => $date,
            'leave_kind' => 'compensatory',
            'request_status' => $status,
        ]);
    }

    public function test_holiday_work_grant_is_consumed_by_approval_and_reflected_on_the_attendance_day(): void
    {
        $employee = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();

        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');

        // 申請中: 休暇ビューに申請中の代休が入るが、残数は減らない(残数は承認時に消化する)。
        $this->assertLeaveRow($employee, '2026-09-10', 'submitted');
        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $grant->refresh();
        $this->assertEquals(0.0, (float) $grant->remaining_days);
        $this->assertEquals(1.0, (float) $grant->used_days);
        $this->assertLeaveRow($employee, '2026-09-10', 'approved');
        $this->assertDatabaseHas('compensatory_leave_usages', [
            'compensatory_leave_request_id' => $requestId,
            'is_confirmed' => true,
            'unallocated_days' => 0.0,
        ]);

        // 休暇だけの日は勤怠日が休暇の反映で作られる(source=leave。日次計算の対象)。
        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->firstOrFail();
        $this->assertSame(AttendanceDaySource::LEAVE, $day->source);
    }

    public function test_returned_request_releases_the_balance_and_resubmitting_restores_it(): void
    {
        $employee = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();
        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/return", [
            'comment' => '日付を見直してください',
        ])->assertOk()->assertJsonPath('status', 'returned');

        // 差戻し: 消化記録は取り消され、残数は戻り、休暇ビューは差戻しになる。
        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);
        $this->assertSame(0, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        $this->assertLeaveRow($employee, '2026-09-10', 'returned');

        // 再提出(申請詳細の「提出する」): 同じ内容で申請中に戻り、新しい消化記録が作られる(論点8)。
        $this->actingAs($employee)->postJson('/api/workflow-requests/'.$this->workflowIdOf($requestId).'/submit')
            ->assertSuccessful();

        $this->assertLeaveRow($employee, '2026-09-10', 'submitted');
        $this->assertSame(1, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        $this->assertEquals(0.0, (float) $grant->refresh()->remaining_days);
        $this->assertLeaveRow($employee, '2026-09-10', 'approved');
    }

    public function test_cancelling_a_pending_request_cancels_the_workflow_and_releases_the_leave_day(): void
    {
        $employee = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();
        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');

        $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', WorkflowRequest::query()->where('subject_id', $requestId)->value('status'));
        $this->assertLeaveRow($employee, '2026-09-10', 'cancelled');
        $this->assertSame(0, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        // 休暇だけで記録された勤怠日(実績なし)は削除される(論点15)。
        $this->assertNull(AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->first());
        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);
    }

    public function test_admin_cancelling_an_approved_request_keeps_the_workflow_approved_and_restores_the_balance(): void
    {
        $employee = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();
        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        $this->actingAs($this->hrStaff())->postJson("/api/compensatory-leave/requests/{$requestId}/admin-cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        // 承認済みのワークフローは状態を変えない(設計原則13)。業務側の取消は休暇申請のイベントとして残る。
        $this->assertSame('approved', WorkflowRequest::query()->where('subject_id', $requestId)->value('status'));
        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);
        $this->assertLeaveRow($employee, '2026-09-10', 'cancelled');
    }

    public function test_sibling_requests_of_a_group_are_approved_together_even_when_one_is_returned(): void
    {
        $employee = User::factory()->create();
        $this->holidayWorkGrantedAndConfirmed($employee, ['2026-08-08', '2026-08-09']);
        $approver = User::factory()->create();
        $groupId = (string) Str::uuid();

        $first = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10', 'full', ['request_group_id' => $groupId]);
        $second = $this->requestCompensatoryLeave($employee, $approver, '2026-09-11', 'full', ['request_group_id' => $groupId]);

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$second}/return", ['comment' => '差戻し'])->assertOk();

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$first}/approve")->assertOk();

        $this->assertSame('approved', CompensatoryLeaveRequest::query()->whereKey($first)->value('status'));
        $this->assertSame('returned', CompensatoryLeaveRequest::query()->whereKey($second)->value('status'));
        $this->assertLeaveRow($employee, '2026-09-10', 'approved');
        $this->assertLeaveRow($employee, '2026-09-11', 'returned');
    }

    public function test_approval_with_insufficient_balance_is_allowed_and_records_the_unallocated_days(): void
    {
        $employee = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();
        $first = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');
        $second = $this->requestCompensatoryLeave($employee, $approver, '2026-09-11');

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$first}/approve")->assertOk();
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$second}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertEquals(0.0, (float) $grant->refresh()->remaining_days);
        $this->assertDatabaseHas('compensatory_leave_usages', [
            'compensatory_leave_request_id' => $second,
            'is_confirmed' => true,
            'unallocated_days' => 1.0,
        ]);
    }

    public function test_reprocessing_the_same_approval_changes_nothing(): void
    {
        $employee = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();
        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        // Reactorから同じ承認が再度届いても(viaReactor)、状態は変わらない(冪等)。
        app(CommandBus::class)->dispatch(new ApproveCompensatoryLeaveRequest(
            compensatoryLeaveRequestId: $requestId,
            approvedByUserId: $approver->id,
            viaReactor: true,
            initiatedByUserId: $approver->id,
        ));

        $this->assertEquals(0.0, (float) $grant->refresh()->remaining_days);
        $this->assertEquals(1.0, (float) $grant->used_days);
        $this->assertSame(1, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        $this->assertLeaveRow($employee, '2026-09-10', 'approved');
    }

    public function test_a_same_day_conflict_is_rejected_and_no_context_changes(): void
    {
        $employee = User::factory()->create();
        $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();
        $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');

        $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);

        // 拒否された連鎖は全体が取り消される(申請・ワークフロー・休暇ビュー・残数)。
        $this->assertSame(1, CompensatoryLeaveRequest::query()->where('user_id', $employee->id)->count());
        $this->assertSame(1, WorkflowRequest::query()->where('subject_type', 'compensatory_leave_request')->count());
        $this->assertSame(1, \DB::table('attendance_day_leaves')->where('user_id', $employee->id)->count());
    }

    public function test_deleting_an_unconfirmed_holiday_work_day_removes_its_draft_grant(): void
    {
        $employee = User::factory()->create();
        $this->enableCompensatoryLeave();
        $workStyle = $this->makeCompensatoryWorkStyle();
        $this->makeCompensatoryHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordCompensatoryAttendance($employee, '2026-08-08', '09:00', '17:00', [
            ['start' => '2026-08-08T12:00:00+09:00', 'end' => '2026-08-08T13:00:00+09:00'],
        ]);

        $grantId = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->value('id');
        $this->assertNotNull($grantId);
        $this->assertSame('draft', CompensatoryLeaveGrant::query()->whereKey($grantId)->value('status'));

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-08')->firstOrFail();
        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$day->id}", [
            'reason' => 'テスト削除',
        ])->assertSuccessful();

        // 休日出勤の実績が無くなったため、未確定(下書き)の付与は外れる(同期はイベントから行う)。
        $this->assertFalse(CompensatoryLeaveGrant::query()->whereKey($grantId)->exists());
    }
}
