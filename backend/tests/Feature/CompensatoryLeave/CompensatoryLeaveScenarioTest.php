<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayLeave;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveUsage;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
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

    /** ワークフロー側からの取消(申請中): 代休申請・消化記録・休暇ビューが取消になり、残数が戻り、休暇だけの勤怠日が消える。 */
    public function test_cancelling_the_workflow_of_a_pending_request_cancels_the_request_usage_and_leave_day(): void
    {
        $employee = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();
        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');
        $this->assertSame(1, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        $this->assertNotNull(AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->first());

        $this->actingAs($employee)->postJson('/api/workflow-requests/'.$this->workflowIdOf($requestId).'/cancel', [
            'reason' => '取り下げ',
        ])->assertSuccessful();

        $this->assertSame('cancelled', CompensatoryLeaveRequest::query()->whereKey($requestId)->value('status'));
        $this->assertSame('cancelled', WorkflowRequest::query()->where('subject_id', $requestId)->value('status'));
        $this->assertLeaveRow($employee, '2026-09-10', 'cancelled');
        $this->assertSame(0, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        $this->assertNull(AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->first());
        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);
    }

    /** ワークフロー側からの取消(差戻し中): 差戻しで消化記録と勤怠日は既に外れているため、申請と休暇ビューの状態だけが変わり、残数は変わらない。 */
    public function test_cancelling_the_workflow_of_a_returned_request_cancels_the_request_and_keeps_the_balance(): void
    {
        $employee = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);
        $approver = User::factory()->create();
        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/return", [
            'comment' => '日付を見直してください',
        ])->assertOk();
        $this->assertLeaveRow($employee, '2026-09-10', 'returned');
        $this->assertSame(0, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);

        $this->actingAs($employee)->postJson('/api/workflow-requests/'.$this->workflowIdOf($requestId).'/cancel', [
            'reason' => '取り下げ',
        ])->assertSuccessful();

        $this->assertSame('cancelled', CompensatoryLeaveRequest::query()->whereKey($requestId)->value('status'));
        $this->assertSame('cancelled', WorkflowRequest::query()->where('subject_id', $requestId)->value('status'));
        $this->assertLeaveRow($employee, '2026-09-10', 'cancelled');
        $this->assertSame(0, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        $this->assertNull(AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->first());
        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);
    }

    /**
     * 締め済み(月次提出済み)の日: 申請・承認・差戻し・取消・再提出(ワークフロー側の取消を含む)は全て拒否され、
     * どの文脈の状態(申請・ワークフロー・休暇ビュー・残数・消化記録・勤怠日・イベント件数)も変わらない。
     */
    public function test_closed_month_rejects_every_transition_and_no_context_changes(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $grant = $this->holidayWorkGrantedAndConfirmed($employee);

        // 9月の申請: 申請中のもの(締め後に承認・差戻し・取消を試す)と、差戻し中のもの(締め後に再提出・取消を試す)。
        $pending = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');
        $returned = $this->requestCompensatoryLeave($employee, $approver, '2026-09-11');
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$returned}/return", [
            'comment' => '日付を見直してください',
        ])->assertOk();
        $pendingWorkflowId = $this->workflowIdOf($pending);
        $returnedWorkflowId = $this->workflowIdOf($returned);

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-09/submit', [
            'approver_user_id' => User::factory()->create()->id,
        ])->assertSuccessful();
        $this->assertSame(AttendanceMonthStatus::SUBMITTED, AttendanceMonth::query()
            ->where('user_id', $employee->id)->where('year_month', '2026-09')->value('status'));

        $this->makeCompensatoryWorkingDayShift($employee, WorkStyle::query()->firstOrFail(), '2026-09-12');
        $before = $this->compensatoryStateOf($employee, $grant->id);

        $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-12',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ])->assertStatus(422);
        $this->assertSame($before, $this->compensatoryStateOf($employee, $grant->id), '締め済みの日への申請');

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$pending}/approve")->assertStatus(422);
        $this->assertSame($before, $this->compensatoryStateOf($employee, $grant->id), '締め済みの日の承認');

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$pending}/return", [
            'comment' => '差戻し',
        ])->assertStatus(422);
        $this->assertSame($before, $this->compensatoryStateOf($employee, $grant->id), '締め済みの日の差戻し');

        $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$pending}/cancel")->assertStatus(422);
        $this->assertSame($before, $this->compensatoryStateOf($employee, $grant->id), '締め済みの日の取消(申請者)');

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$pendingWorkflowId}/cancel", [
            'reason' => '取り下げ',
        ])->assertStatus(422);
        $this->assertSame($before, $this->compensatoryStateOf($employee, $grant->id), '締め済みの日の取消(ワークフロー側)');

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$returnedWorkflowId}/submit")->assertStatus(422);
        $this->assertSame($before, $this->compensatoryStateOf($employee, $grant->id), '締め済みの日の再提出');

        $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$returned}/cancel")->assertStatus(422);
        $this->assertSame($before, $this->compensatoryStateOf($employee, $grant->id), '締め済みの日の差戻し中の取消');
    }

    /**
     * 移行後の申請: 移行コマンドで口座へ引き継いだ付与(本変更前の付与)を、新しい申請の承認・取消が使う。
     */
    public function test_requests_after_the_migration_use_the_migrated_grant(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->makeCompensatoryWorkStyle();
        $grantId = $this->legacyCompensatoryGrant($employee);

        $this->artisan('compensatory-leave:migrate-to-account', ['--apply' => true])->assertSuccessful();
        $this->assertTrue($this->compensatoryAccountOf($employee)->isMigrated());

        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');
        $this->assertEquals(1.0, (float) CompensatoryLeaveGrant::query()->whereKey($grantId)->value('remaining_days'));

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertEquals(0.0, (float) CompensatoryLeaveGrant::query()->whereKey($grantId)->value('remaining_days'));
        $this->assertEquals(1.0, (float) CompensatoryLeaveGrant::query()->whereKey($grantId)->value('used_days'));
        $this->assertDatabaseHas('compensatory_leave_usages', [
            'compensatory_leave_request_id' => $requestId,
            'is_confirmed' => true,
            'unallocated_days' => 0.0,
        ]);
        $this->assertLeaveRow($employee, '2026-09-10', 'approved');

        $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertEquals(1.0, (float) CompensatoryLeaveGrant::query()->whereKey($grantId)->value('remaining_days'));
        $this->assertSame(0, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());
        $this->assertLeaveRow($employee, '2026-09-10', 'cancelled');
    }

    /** 本変更前の代休付与(旧テーブル)。移行テストと同じ作り方で、移行コマンドの入力を再現する。 */
    private function legacyCompensatoryGrant(User $user, string $workDate = '2026-08-08'): string
    {
        $grantId = (string) Str::uuid();

        CompensatoryLeaveGrant::query()->create([
            'id' => $grantId,
            'user_id' => $user->id,
            'source' => 'manual',
            'attendance_day_id' => null,
            'work_date' => $workDate,
            'granted_days' => 1.0,
            'granted_minutes' => null,
            'used_days' => 0,
            'used_minutes' => null,
            'remaining_days' => 1.0,
            'remaining_minutes' => null,
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'expires_on' => null,
            'grant_reason' => null,
        ]);

        return $grantId;
    }

    /**
     * 代休の文脈ごとの状態(申請・ワークフロー・休暇ビュー・残数・消化記録・勤怠日・イベント件数)。
     * 拒否された操作の前後で比べ、どの文脈も変わっていないことを確かめる。
     *
     * @return array<string, mixed>
     */
    private function compensatoryStateOf(User $employee, string $grantId): array
    {
        return [
            'requests' => CompensatoryLeaveRequest::query()->where('user_id', $employee->id)->orderBy('id')->pluck('status', 'id')->all(),
            'workflows' => WorkflowRequest::query()->where('subject_type', 'compensatory_leave_request')->orderBy('id')->pluck('status', 'id')->all(),
            'leaves' => AttendanceDayLeave::query()->where('user_id', $employee->id)
                ->where('leave_kind', AttendanceDayLeave::KIND_COMPENSATORY)
                ->orderBy('leave_request_id')->pluck('request_status', 'leave_request_id')->all(),
            'remaining' => (float) CompensatoryLeaveGrant::query()->whereKey($grantId)->value('remaining_days'),
            'usages' => CompensatoryLeaveUsage::query()->count(),
            'days' => AttendanceDay::query()->where('user_id', $employee->id)->orderBy('id')->pluck('source')->all(),
            'events' => \DB::table('stored_events')->count(),
        ];
    }
}
