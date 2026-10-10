<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Commands\ApplyLeaveToAttendanceDay;
use App\Domain\Attendance\Commands\RecalculateAttendanceDayForLeave;
use App\Domain\Attendance\Commands\ReleaseLeaveFromAttendanceDay;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\AuthenticationKeyType;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayLeave;
use App\Models\CompanyCalendar;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveUsage;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 休暇(有給)の申請・承認・差戻し・取消が勤怠へ連鎖するシナリオテスト(UI非依存。API経由でSQLite上に通す)。
 * 休暇ビュー(attendance_day_leaves)・勤怠日(attendance_days)・日次計算(attendance_daily_calculations)・
 * 有給申請・消化記録・ワークフローの状態を、各ステップの後で確認する。失敗系は全文脈の状態が変わらないことを
 * stored_eventsの件数などで確認する。
 */
class AttendanceLeaveScenarioTest extends TestCase
{
    use RefreshDatabase;

    private ?WorkStyle $style = null;

    /** @return array{0: User, 1: User} 申請者と承認者 */
    private function people(): array
    {
        return [User::factory()->create(), User::factory()->create()];
    }

    /** 勤務予定日(所定480分)を作る。1つのテストの中では同じ働き方を使う。 */
    private function workingDay(User $user, string $date): void
    {
        if ($this->style === null) {
            $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
            $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
            $this->style = WorkStyle::query()->create([
                'code' => 'standard-leave-scenario', 'name' => '通常勤務', 'work_time_system' => 'fixed',
                'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
                'default_start_time' => '09:00', 'default_end_time' => '18:00',
                'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
            ]);
        }

        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $this->style->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    private function grantPaidLeave(User $employee): void
    {
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));
    }

    /** 有給を申請し、申請IDを返す。 */
    private function requestLeave(User $employee, User $approver, string $date, string $leaveType, ?float $hours = null): string
    {
        $payload = [
            'target_date' => $date,
            'leave_type' => $leaveType,
            'approver_user_id' => $approver->id,
        ];
        if ($hours !== null) {
            $payload['hours'] = $hours;
        }

        return $this->actingAs($employee)->postJson('/api/paid-leave/requests', $payload)
            ->assertCreated()->json('id');
    }

    private function approveLeave(User $approver, string $requestId): void
    {
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();
    }

    private function dayOn(User $user, string $date): ?AttendanceDay
    {
        return AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', $date)->first();
    }

    private function calcOn(User $user, string $date): ?AttendanceDailyCalculation
    {
        $day = $this->dayOn($user, $date);

        return $day === null ? null : AttendanceDailyCalculation::query()->where('attendance_day_id', $day->id)->first();
    }

    private function leaveStatus(string $requestId): ?string
    {
        return AttendanceDayLeave::query()->where('leave_kind', 'paid')->where('leave_request_id', $requestId)->value('request_status');
    }

    /**
     * 失敗系で「どの文脈も変わっていない」ことを確かめるための状態の要約。
     *
     * @return array<string, mixed>
     */
    private function snapshotState(): array
    {
        return [
            'stored_events' => DB::table('stored_events')->count(),
            'workflow_requests' => WorkflowRequest::query()->count(),
            'paid_leave_requests' => PaidLeaveRequest::query()->orderBy('id')->get(['id', 'status'])->toArray(),
            'paid_leave_usages' => PaidLeaveUsage::query()->count(),
            'attendance_days' => AttendanceDay::query()->orderBy('id')->get(['id', 'source', 'actual_start_at'])->toArray(),
            'leave_rows' => AttendanceDayLeave::query()->orderBy('leave_request_id')->get(['leave_request_id', 'request_status', 'unit', 'minutes'])->toArray(),
            'calculations' => AttendanceDailyCalculation::query()->orderBy('attendance_day_id')
                ->get(['attendance_day_id', 'paid_leave_days', 'paid_leave_minutes', 'prescribed_work_minutes', 'work_minutes'])->toArray(),
        ];
    }

    public function test_a_paid_leave_request_creates_a_leave_only_day_with_its_calculation(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);

        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');

        $day = $this->dayOn($employee, '2026-08-10');
        $this->assertNotNull($day);
        $this->assertSame('leave', $day->source);
        $this->assertSame('not_started', $day->status);
        $this->assertNull($day->actual_start_at);
        $this->assertSame('submitted', $this->leaveStatus($requestId));

        $calc = $this->calcOn($employee, '2026-08-10');
        $this->assertEquals(1.0, $calc->paid_leave_days);
        $this->assertEquals(0, $calc->work_minutes);
        $this->assertEquals(480, $calc->prescribed_work_minutes);
    }

    public function test_approving_keeps_the_same_day_and_the_same_calculation(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $dayId = $this->dayOn($employee, '2026-08-10')->id;
        $before = $this->calcOn($employee, '2026-08-10')->only(['paid_leave_days', 'prescribed_work_minutes', 'work_minutes']);

        $this->approveLeave($approver, $requestId);

        $this->assertSame('approved', PaidLeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame('approved', $this->leaveStatus($requestId));
        $this->assertSame($dayId, $this->dayOn($employee, '2026-08-10')->id);
        $after = $this->calcOn($employee, '2026-08-10')->only(['paid_leave_days', 'prescribed_work_minutes', 'work_minutes']);
        $this->assertEquals($before, $after);
    }

    public function test_cancelling_an_approved_leave_on_an_empty_day_deletes_the_day(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $this->approveLeave($approver, $requestId);

        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertOk();

        $this->assertNull($this->dayOn($employee, '2026-08-10'));
        $this->assertSame('cancelled', $this->leaveStatus($requestId));
        $this->assertSame('cancelled', PaidLeaveRequest::query()->findOrFail($requestId)->status);
    }

    public function test_returning_a_leave_on_an_empty_day_deletes_the_day(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $this->assertNotNull($this->dayOn($employee, '2026-08-10'));

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/return", ['comment' => 'やり直してください'])
            ->assertOk();

        $this->assertNull($this->dayOn($employee, '2026-08-10'));
        $this->assertSame('returned', $this->leaveStatus($requestId));
    }

    public function test_editing_a_leave_day_keeps_the_leave_and_its_values(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'am_half');
        $this->approveLeave($approver, $requestId);
        $day = $this->dayOn($employee, '2026-08-10');

        $this->actingAs($employee)->putJson("/api/attendance/days/{$day->id}", [
            'work_type' => null,
            'actual_start_at' => '2026-08-10T13:00:00+09:00',
            'actual_end_at' => '2026-08-10T17:00:00+09:00',
            'reason' => '日次編集のテスト',
        ])->assertOk();

        $this->assertSame('approved', $this->leaveStatus($requestId));
        $calc = $this->calcOn($employee, '2026-08-10');
        $this->assertEquals(0.5, $calc->paid_leave_days);
        $this->assertEquals(240, $calc->prescribed_work_minutes);
        $this->assertEquals(240, $calc->work_minutes);
    }

    public function test_a_punched_half_day_is_kept_and_recalculated_when_the_leave_is_cancelled(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-12');
        $this->grantPaidLeave($employee);
        $requestId = $this->requestLeave($employee, $approver, '2026-08-12', 'am_half');
        $this->approveLeave($approver, $requestId);

        // 半休の日は打刻できる。打刻の取り込みで source=leave の日が punch になる(上書きできる)。
        $this->actingAs($employee)->postJson('/api/attendance-punches', [
            'work_date' => '2026-08-12', 'punch_type' => 'clock_in', 'punched_at' => '2026-08-12T13:00:00+09:00', 'source' => 'web',
        ])->assertOk();
        $this->actingAs($employee)->postJson('/api/attendance-punches', [
            'work_date' => '2026-08-12', 'punch_type' => 'clock_out', 'punched_at' => '2026-08-12T17:00:00+09:00', 'source' => 'web',
        ])->assertOk();

        $day = $this->dayOn($employee, '2026-08-12');
        $this->assertSame('punch', $day->source);
        $this->assertEquals(240, $this->calcOn($employee, '2026-08-12')->work_minutes);

        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertOk();

        // 打刻済みの日は取消しても残り、休暇の分が外れて再計算される。
        $day = $this->dayOn($employee, '2026-08-12');
        $this->assertNotNull($day);
        $this->assertSame('punch', $day->source);
        $calc = $this->calcOn($employee, '2026-08-12');
        $this->assertEquals(0.0, $calc->paid_leave_days);
        $this->assertEquals(480, $calc->prescribed_work_minutes);
        $this->assertEquals(240, $calc->work_minutes);
    }

    public function test_a_second_full_day_request_on_the_same_day_is_rejected_and_nothing_changes(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);
        $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $before = $this->snapshotState();

        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10', 'leave_type' => 'am_half', 'approver_user_id' => $approver->id,
        ])->assertStatus(422);

        $this->assertSame($before, $this->snapshotState());
    }

    public function test_morning_and_afternoon_half_day_leaves_are_both_accepted_and_count_as_a_full_day(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);

        $this->requestLeave($employee, $approver, '2026-08-10', 'am_half');
        $this->requestLeave($employee, $approver, '2026-08-10', 'pm_half');

        $calc = $this->calcOn($employee, '2026-08-10');
        $this->assertEquals(1.0, $calc->paid_leave_days);
        // 午前半休+午後半休は全休と同じ扱い(所定労働時間は半分にしない)。
        $this->assertEquals(480, $calc->prescribed_work_minutes);
        $this->assertSame(2, AttendanceDayLeave::query()->where('work_date', '2026-08-10')->count());
    }

    public function test_hourly_leaves_whose_total_exceeds_the_prescribed_minutes_are_rejected(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);

        $this->requestLeave($employee, $approver, '2026-08-10', 'hourly', 5.0);
        $before = $this->snapshotState();

        // 5時間+4時間=9時間 > 所定8時間 は拒否。
        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10', 'leave_type' => 'hourly', 'hours' => 4, 'approver_user_id' => $approver->id,
        ])->assertStatus(422);
        $this->assertSame($before, $this->snapshotState());

        // 5時間+3時間=8時間(所定ちょうど)は通る。
        $this->requestLeave($employee, $approver, '2026-08-10', 'hourly', 3.0);
        $this->assertEquals(480, $this->calcOn($employee, '2026-08-10')->paid_leave_minutes);
    }

    public function test_a_request_on_a_day_in_a_submitted_month_is_rejected_and_a_cancel_is_too(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->workingDay($employee, '2026-08-11');
        $this->grantPaidLeave($employee);
        $approvedId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $this->approveLeave($approver, $approvedId);

        $monthApprover = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => $monthApprover->id,
        ])->assertSuccessful();
        $before = $this->snapshotState();

        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-11', 'leave_type' => 'full', 'approver_user_id' => $approver->id,
        ])->assertStatus(422);
        $this->assertNull($this->dayOn($employee, '2026-08-11'));

        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$approvedId}/cancel")->assertStatus(422);

        $this->assertSame($before, $this->snapshotState());
    }

    /**
     * 同じ休暇の反映(Reactorの処理)を2回実行しても結果が変わらない。取消後の2回目の解除も例外にならない。
     */
    public function test_replaying_the_same_leave_commands_gives_the_same_result(): void
    {
        [$employee, $approver] = $this->people();
        $this->workingDay($employee, '2026-08-10');
        $this->grantPaidLeave($employee);
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $bus = app(CommandBus::class);

        $afterRequest = $this->snapshotState();
        $bus->dispatch(new ApplyLeaveToAttendanceDay(
            userId: $employee->id, workDate: '2026-08-10', leaveKind: 'paid', leaveRequestId: $requestId,
            viaReactor: true, initiatedByUserId: $employee->id,
        ));
        $this->assertSame(
            $afterRequest['attendance_days'],
            $this->snapshotState()['attendance_days'],
        );
        $this->assertSame(
            $afterRequest['calculations'],
            $this->snapshotState()['calculations'],
        );

        $this->approveLeave($approver, $requestId);
        $afterApprove = $this->snapshotState();
        $bus->dispatch(new RecalculateAttendanceDayForLeave(
            leaveKind: 'paid', leaveRequestId: $requestId, viaReactor: true, initiatedByUserId: $approver->id,
        ));
        $this->assertSame($afterApprove['calculations'], $this->snapshotState()['calculations']);
        $this->assertSame($afterApprove['attendance_days'], $this->snapshotState()['attendance_days']);

        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertOk();
        $this->assertNull($this->dayOn($employee, '2026-08-10'));

        $bus->dispatch(new ReleaseLeaveFromAttendanceDay(
            leaveKind: 'paid', leaveRequestId: $requestId, viaReactor: true, initiatedByUserId: $employee->id,
        ));
        $this->assertNull($this->dayOn($employee, '2026-08-10'));
        $this->assertSame(0, AttendanceDay::query()->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** 出勤APIの「今日」を2026-08-10(社員の既定タイムゾーン)に固定する。 */
    private function freezeToday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00', 'Asia/Tokyo'));
    }

    // ---- 全休・半休と出勤・打刻・未出勤の判定(休暇ビュー。論点9・仕様確定事項I) ----

    public function test_a_full_day_paid_leave_blocks_clock_in_and_the_today_api_shows_the_leave(): void
    {
        $this->freezeToday();
        [$employee, $approver] = $this->people();
        $this->grantPaidLeave($employee);
        $this->workingDay($employee, '2026-08-10');
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $this->approveLeave($approver, $requestId);

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')
            ->assertUnprocessable()
            ->assertJsonPath('message', '本日は全休の休暇のため出勤できません。');

        // 出勤できない: 打刻は記録されず、休暇だけの日の状態(未開始)は変わらない。
        $this->assertSame(0, DB::table('attendance_punches')->count());
        $this->assertSame('not_started', $this->dayOn($employee, '2026-08-10')->status);

        // 今日の勤怠の応答に休暇が載る(status ではなく leaves で休暇を判定する)。
        $this->actingAs($employee)->getJson('/api/attendance/today')
            ->assertOk()
            ->assertJsonPath('leaves.0.leave_kind', 'paid')
            ->assertJsonPath('leaves.0.unit', 'full')
            ->assertJsonPath('leaves.0.request_id', $requestId)
            ->assertJsonPath('leaves.0.request_status', 'approved');
    }

    public function test_morning_and_afternoon_half_day_leaves_also_block_clock_in(): void
    {
        $this->freezeToday();
        [$employee, $approver] = $this->people();
        $this->grantPaidLeave($employee);
        $this->workingDay($employee, '2026-08-10');
        $amId = $this->requestLeave($employee, $approver, '2026-08-10', 'am_half');
        $pmId = $this->requestLeave($employee, $approver, '2026-08-10', 'pm_half');
        $this->approveLeave($approver, $amId);
        $this->approveLeave($approver, $pmId);

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')
            ->assertUnprocessable()
            ->assertJsonPath('message', '本日は全休の休暇のため出勤できません。');
        $this->assertSame(0, DB::table('attendance_punches')->count());
    }

    public function test_a_half_day_leave_still_allows_clock_in(): void
    {
        $this->freezeToday();
        [$employee, $approver] = $this->people();
        $this->grantPaidLeave($employee);
        $this->workingDay($employee, '2026-08-10');
        $this->requestLeave($employee, $approver, '2026-08-10', 'am_half');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')
            ->assertSuccessful()
            ->assertJsonPath('status', 'working');
    }

    public function test_returning_the_full_day_leave_allows_clock_in_again(): void
    {
        $this->freezeToday();
        [$employee, $approver] = $this->people();
        $this->grantPaidLeave($employee);
        $this->workingDay($employee, '2026-08-10');
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertUnprocessable();

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/return", ['comment' => '日程を確認してください'])->assertOk();

        // 差戻しで休暇が外れると、その日は全休ではなくなり出勤できる。休暇の応答からも外れる。
        $this->actingAs($employee)->getJson('/api/attendance/today')->assertOk()->assertJsonPath('leaves', []);
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')
            ->assertSuccessful()
            ->assertJsonPath('status', 'working');
    }

    public function test_a_full_day_leave_is_not_counted_as_a_missing_punch(): void
    {
        [$employee, $approver] = $this->people();
        $this->grantPaidLeave($employee);
        $this->workingDay($employee, '2026-08-03');
        $this->workingDay($employee, '2026-08-04');
        // 全休の日(08-03)は打刻漏れの対象外。半休だけの日(08-04)は勤務すべき日なので対象に残る。
        $this->requestLeave($employee, $approver, '2026-08-03', 'full');
        $this->requestLeave($employee, $approver, '2026-08-04', 'am_half');

        $this->actingAs($employee)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'カード',
            'raw_key_value' => 'NFC-LEAVE-001',
        ])->assertCreated();
        // 社員としての認証を解除してから、端末(Sanctum)として打刻する。
        $this->app['auth']->forgetGuards();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'default_work_location_type' => 'office',
        ]);
        Sanctum::actingAs($device, ['recorder:punch']);

        $this->postJson('/api/device-punches', [
            'work_date' => '2026-08-10',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-08-10T09:00:00+09:00',
            'authentication_key_value' => 'NFC-LEAVE-001',
        ])
            ->assertSuccessful()
            ->assertJsonPath('attendance_summary.missing_punch_count', 1);
    }

    // ---- 有給申請中の月次提出のガード(論点14) ----

    public function test_a_submitted_paid_leave_blocks_the_month_submission_until_it_is_returned(): void
    {
        [$employee, $approver] = $this->people();
        $this->grantPaidLeave($employee);
        $this->workingDay($employee, '2026-08-10');
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', ['approver_user_id' => $approver->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', '対象月に未承認の有給申請があります。有給申請の承認を完了してから月次勤怠を提出してください。');
        $this->assertSame(0, DB::table('attendance_months')->where('user_id', $employee->id)->count());

        // 差し戻された有給は申請前の状態のため、月次提出を妨げない。
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/return", ['comment' => '日程を確認してください'])->assertOk();

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', ['approver_user_id' => $approver->id])
            ->assertSuccessful()
            ->assertJsonPath('status', 'submitted');
    }

    public function test_an_approved_paid_leave_does_not_block_the_month_submission(): void
    {
        [$employee, $approver] = $this->people();
        $this->grantPaidLeave($employee);
        $this->workingDay($employee, '2026-08-10');
        $requestId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $this->approveLeave($approver, $requestId);

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', ['approver_user_id' => $approver->id])
            ->assertSuccessful()
            ->assertJsonPath('status', 'submitted');
    }

    // ---- 勤怠APIの leaves(休暇ビューの有効な行) ----

    public function test_the_month_api_returns_the_leaves_of_each_day_and_no_consumption_list(): void
    {
        [$employee, $approver] = $this->people();
        $this->grantPaidLeave($employee);
        $this->workingDay($employee, '2026-08-10');
        $this->workingDay($employee, '2026-08-11');
        $fullId = $this->requestLeave($employee, $approver, '2026-08-10', 'full');
        $this->requestLeave($employee, $approver, '2026-08-11', 'am_half');
        $this->requestLeave($employee, $approver, '2026-08-11', 'pm_half');

        $response = $this->actingAs($employee)->getJson('/api/attendance/months/2026-08')->assertOk();
        $days = collect($response->json('days'))->keyBy('work_date');

        $this->assertArrayNotHasKey('special_leave_usages', $days['2026-08-10']);
        $this->assertSame(1, count($days['2026-08-10']['leaves']));
        $leave = $days['2026-08-10']['leaves'][0];
        $this->assertSame('paid', $leave['leave_kind']);
        $this->assertSame('full', $leave['unit']);
        $this->assertNull($leave['special_leave_type_id']);
        $this->assertSame($fullId, $leave['request_id']);
        $this->assertSame('submitted', $leave['request_status']);
        $this->assertNotNull($leave['workflow_request_id']);

        $this->assertCount(2, $days['2026-08-11']['leaves']);
        $this->assertSame(['am_half', 'pm_half'], collect($days['2026-08-11']['leaves'])->pluck('unit')->sort()->values()->all());
    }
}
