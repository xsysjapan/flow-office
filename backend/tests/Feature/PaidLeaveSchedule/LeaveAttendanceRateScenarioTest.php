<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Aggregates\EmployeeCalendarEntryAggregate;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Models\CompanyCalendar;
use App\Models\LeaveAttendanceRateDay;
use App\Models\SpecialLeaveAttendanceRateDay;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 有給の出勤率の入力(leave_attendance_rate_days)のシナリオテスト(仕様確定事項F・論点9)。
 * 休暇申請API・ワークフローのコマンド・勤怠のイベントから、出勤率の判定(AttendanceRateAssessor)までを通して確かめる。
 * 休暇は勤怠日のwork_type・statusを書かないため、休暇の出勤扱いは休暇申請のイベント由来の行で判定される。
 */
class LeaveAttendanceRateScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD_START = '2026-08-10';

    private const PERIOD_END = '2026-08-14';

    private User $employee;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->assignWorkingDays($this->employee, ['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14']);
        app(CommandBus::class)->dispatch(new GrantPaidLeave($this->employee->id, '2025-07-01', '2027-06-30', 10.0, null));
    }

    public function test_pending_and_approved_full_day_paid_leave_counts_as_attended(): void
    {
        $requestId = $this->submitRequest(['target_date' => '2026-08-10', 'leave_type' => 'full']);

        // 申請中の全休は休暇として出勤扱い(現行の申請時の作業内容と同じ扱い)。
        $this->assertRow('2026-08-10', attended: false, full: ['paid'], partial: []);
        $this->assertSame(1, $this->assess()->attendanceDays);

        app(CommandBus::class)->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdOf($requestId),
            approvedByUserId: $this->approver->id,
        ));

        // 承認済みでも同じ日の判定は変わらない。
        $this->assertRow('2026-08-10', attended: false, full: ['paid'], partial: []);
        $this->assertSame(1, $this->assess()->attendanceDays);
    }

    public function test_a_returned_leave_is_removed_from_the_attendance_rate(): void
    {
        $requestId = $this->submitRequest(['target_date' => '2026-08-11', 'leave_type' => 'full']);
        $this->assertRow('2026-08-11', attended: false, full: ['paid'], partial: []);

        app(CommandBus::class)->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdOf($requestId),
            returnedByUserId: $this->approver->id,
            comment: '日付を見直してください',
        ));

        // 差戻しの休暇は申請前の状態のため、出勤として数えない。
        $this->assertRow('2026-08-11', attended: false, full: [], partial: []);
        $this->assertSame(0, $this->assess()->attendanceDays);
    }

    public function test_clocking_out_counts_the_day_as_attended(): void
    {
        AttendanceDayAggregate::retrieve((string) Str::uuid())->create(
            userId: $this->employee->id,
            workDate: '2026-08-12',
            calendarEntryId: null,
            status: 'clocked_out',
            source: 'punch',
            utcOffsetMinutes: 540,
            actualStartAt: null,
            actualEndAt: null,
            workType: null,
            workLocationType: null,
            note: null,
            breaks: [],
            leaveSegments: [],
            reason: 'test',
            createdByUserId: $this->employee->id,
        )->persist();

        $this->assertRow('2026-08-12', attended: true, full: [], partial: []);
        $this->assertSame(1, $this->assess()->attendanceDays);
    }

    public function test_am_and_pm_half_day_leaves_make_a_full_day(): void
    {
        $this->submitRequest(['target_date' => '2026-08-13', 'leave_type' => 'am_half']);
        $this->assertRow('2026-08-13', attended: false, full: [], partial: ['paid']);

        $this->submitRequest(['target_date' => '2026-08-13', 'leave_type' => 'pm_half']);
        // 午前半休と午後半休がそろう日は全休として出勤扱い。
        $this->assertRow('2026-08-13', attended: false, full: ['paid'], partial: []);
    }

    public function test_hourly_paid_leave_counts_as_partial_for_the_paid_rule(): void
    {
        $this->submitRequest(['target_date' => '2026-08-14', 'leave_type' => 'hourly', 'hours' => 2.0]);

        $this->assertRow('2026-08-14', attended: false, full: [], partial: ['paid']);
        $this->assertSame(1, $this->assess()->attendanceDays);
    }

    public function test_the_period_rate_follows_the_leave_and_attendance_events_in_order(): void
    {
        // 08-10 全休(承認済み)・08-11 全休(差戻し)・08-12 退勤・08-13 午前+午後半休・08-14 時間休
        // → 分母5、分子4(08-11を除く)=80%。
        $requestId = $this->submitRequest(['target_date' => '2026-08-10', 'leave_type' => 'full']);
        app(CommandBus::class)->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdOf($requestId),
            approvedByUserId: $this->approver->id,
        ));

        $returnedId = $this->submitRequest(['target_date' => '2026-08-11', 'leave_type' => 'full']);
        app(CommandBus::class)->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdOf($returnedId),
            returnedByUserId: $this->approver->id,
            comment: '差戻し',
        ));

        AttendanceDayAggregate::retrieve((string) Str::uuid())->create(
            userId: $this->employee->id,
            workDate: '2026-08-12',
            calendarEntryId: null,
            status: 'clocked_out',
            source: 'punch',
            utcOffsetMinutes: 540,
            actualStartAt: null,
            actualEndAt: null,
            workType: null,
            workLocationType: null,
            note: null,
            breaks: [],
            leaveSegments: [],
            reason: 'test',
            createdByUserId: $this->employee->id,
        )->persist();

        $this->submitRequest(['target_date' => '2026-08-13', 'leave_type' => 'am_half']);
        $this->submitRequest(['target_date' => '2026-08-13', 'leave_type' => 'pm_half']);
        $this->submitRequest(['target_date' => '2026-08-14', 'leave_type' => 'hourly', 'hours' => 2.0]);

        $result = $this->assess();
        $this->assertSame(5, $result->denominatorDays);
        $this->assertSame(4, $result->attendanceDays);
        $this->assertEqualsWithDelta(80.0, $result->attendanceRate, 0.001);
    }

    public function test_rebuilding_both_views_from_events_reproduces_the_same_rates(): void
    {
        // 全休(承認済み)・差戻し・退勤・午前+午後半休・時間休を通した後の状態を覚える。
        $requestId = $this->submitRequest(['target_date' => '2026-08-10', 'leave_type' => 'full']);
        app(CommandBus::class)->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdOf($requestId),
            approvedByUserId: $this->approver->id,
        ));
        $returnedId = $this->submitRequest(['target_date' => '2026-08-11', 'leave_type' => 'full']);
        app(CommandBus::class)->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdOf($returnedId),
            returnedByUserId: $this->approver->id,
            comment: '差戻し',
        ));
        AttendanceDayAggregate::retrieve((string) Str::uuid())->create(
            userId: $this->employee->id,
            workDate: '2026-08-12',
            calendarEntryId: null,
            status: 'clocked_out',
            source: 'punch',
            utcOffsetMinutes: 540,
            actualStartAt: null,
            actualEndAt: null,
            workType: null,
            workLocationType: null,
            note: null,
            breaks: [],
            leaveSegments: [],
            reason: 'test',
            createdByUserId: $this->employee->id,
        )->persist();
        $this->submitRequest(['target_date' => '2026-08-13', 'leave_type' => 'am_half']);
        $this->submitRequest(['target_date' => '2026-08-13', 'leave_type' => 'pm_half']);
        $this->submitRequest(['target_date' => '2026-08-14', 'leave_type' => 'hourly', 'hours' => 2.0]);

        $viewsBefore = $this->viewState();
        $resultBefore = $this->assess();

        // 両ビューと休暇・勤怠の行を空にして、イベントから再生成する(PaidLeaveRequestMigrationTestと同じ手順)。
        DB::table('leave_attendance_rate_days')->delete();
        DB::table('leave_attendance_rate_leaves')->delete();
        DB::table('leave_attendance_rate_attendance_days')->delete();
        DB::table('special_leave_attendance_rate_days')->delete();
        DB::table('special_leave_attendance_rate_leaves')->delete();
        DB::table('special_leave_attendance_rate_attendance_days')->delete();
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $this->assertSame($viewsBefore, $this->viewState(), 'rebuilt rate views');
        $resultAfter = $this->assess();
        $this->assertSame($resultBefore->denominatorDays, $resultAfter->denominatorDays);
        $this->assertSame($resultBefore->attendanceDays, $resultAfter->attendanceDays);
        $this->assertEqualsWithDelta(80.0, $resultAfter->attendanceRate, 0.001);
    }

    /** 両ビューの行(利用者・日付・分母・出勤・休暇の種類)と休暇の行の状態。 */
    private function viewState(): array
    {
        $columns = ['user_id', 'work_date', 'is_working_day', 'attended', 'full_leave_kinds', 'partial_leave_kinds'];

        return [
            'paid' => LeaveAttendanceRateDay::query()->orderBy('work_date')->get($columns)->map->toArray()->all(),
            'special' => SpecialLeaveAttendanceRateDay::query()->orderBy('work_date')->get($columns)->map->toArray()->all(),
            'leaves' => DB::table('leave_attendance_rate_leaves')
                ->orderBy('work_date')->orderBy('unit')
                ->get(['leave_kind', 'user_id', 'work_date', 'unit', 'request_status', 'source'])
                ->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /** 対象期間の有給の出勤率(分母・分子)を判定する。 */
    private function assess()
    {
        return (new AttendanceRateAssessor())->assess(
            userId: $this->employee->id,
            periodStart: Carbon::parse(self::PERIOD_START),
            periodEnd: Carbon::parse(self::PERIOD_END),
            minAttendanceRate: 80.0,
        );
    }

    /** 全休・半休・時間休の有給を申請し、申請IDを返す(承認者は$this->approver)。 */
    private function submitRequest(array $overrides): string
    {
        return $this->actingAs($this->employee)->postJson('/api/paid-leave/requests', array_merge([
            'approver_user_id' => $this->approver->id,
            'reason' => '私用のため',
        ], $overrides))->assertCreated()->json('id');
    }

    private function workflowRequestIdOf(string $requestId): string
    {
        return (string) WorkflowRequest::query()
            ->where('subject_type', 'paid_leave_request')
            ->where('subject_id', $requestId)
            ->value('id');
    }

    /** 出勤率ビューの行を確認する(attended・full・partialの種類)。 */
    private function assertRow(string $date, bool $attended, array $full, array $partial): void
    {
        $row = LeaveAttendanceRateDay::query()
            ->where('user_id', $this->employee->id)
            ->whereDate('work_date', $date)
            ->first();

        $this->assertNotNull($row, "attendance rate row for {$date}");
        $this->assertSame($attended, (bool) $row->attended, "attended on {$date}");
        $this->assertSame($full, $row->full_leave_kinds ?? [], "full leave kinds on {$date}");
        $this->assertSame($partial, $row->partial_leave_kinds ?? [], "partial leave kinds on {$date}");
    }

    /** 分母になる所定労働日を、カレンダーの割当イベント(employee_calendar_entry.assigned)で登録する。 */
    private function assignWorkingDays(User $user, array $dates): void
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$user->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        foreach ($dates as $date) {
            EmployeeCalendarEntryAggregate::retrieve((string) Str::uuid())->assign(
                userId: $user->id,
                workDate: $date,
                workStyleId: $workStyle->id,
                shiftPatternId: null,
                dayType: 'weekday',
                isWorkingDay: true,
                isLegalHoliday: false,
                isCompanyHoliday: false,
                plannedStartAt: "{$date} 09:00:00",
                plannedEndAt: "{$date} 18:00:00",
                plannedBreakMinutes: 60,
                plannedBreakStartAt: null,
                plannedBreakEndAt: null,
                isPublished: true,
                isManuallyOverridden: false,
                assignedByUserId: $user->id,
            )->persist();
        }
    }
}
