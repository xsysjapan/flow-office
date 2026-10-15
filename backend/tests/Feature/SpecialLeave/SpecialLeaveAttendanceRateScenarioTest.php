<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Aggregates\EmployeeCalendarEntryAggregate;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Commands\GrantScheduledSpecialLeave;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Models\CompanyCalendar;
use App\Models\SpecialLeaveGrantRule;
use App\Models\SpecialLeaveType;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 特別休暇の自動付与(GrantScheduledSpecialLeaveHandler)の出勤率の判定が、特別休暇の休暇申請(全休)の
 * イベントから作られる出勤率ビューで変わることを、申請API・差戻しコマンド・勤怠のイベントを通して確かめる(仕様確定事項F)。
 *
 * 期間(直近12か月)の所定労働日5日のうち3日を退勤、1日を特別休暇の全休(申請中)にする → 4/5=80%で付与される。
 * その1日を差し戻すと3/5=60%になり付与されない。
 */
class SpecialLeaveAttendanceRateScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-10';

    private User $employee;

    private User $approver;

    private SpecialLeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create(['hire_date' => '2025-08-10']);
        $this->approver = User::factory()->create();
        $this->type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);

        $rule = SpecialLeaveGrantRule::query()->create([
            'special_leave_type_id' => $this->type->id,
            'name' => '誕生日休暇ルール', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 0, 'grant_cycle_months' => 12,
            'expires_after_months' => null, 'is_active' => true,
        ]);
        $rule->steps()->create(['continuous_service_months' => 0, 'grant_days' => 1]);

        // 所定労働日: 今日から7日おきの5日(すべて直近12か月の期間内)。
        $workStyle = $this->createWorkStyle();
        foreach ($this->workingDates() as $date) {
            EmployeeCalendarEntryAggregate::retrieve((string) Str::uuid())->assign(
                userId: $this->employee->id,
                workDate: $date,
                workStyleId: $workStyle->id,
                shiftPatternId: null,
                dayType: 'weekday',
                isWorkingDay: true,
                isLegalHoliday: false,
                isCompanyHoliday: false,
                plannedStartAt: null,
                plannedEndAt: null,
                plannedBreakMinutes: 60,
                plannedBreakStartAt: null,
                plannedBreakEndAt: null,
                isPublished: true,
                isManuallyOverridden: false,
                assignedByUserId: $this->employee->id,
            )->persist();
        }

        // 最初の3日は退勤済み(勤怠のイベント)。
        foreach (array_slice($this->workingDates(), 0, 3) as $date) {
            $this->clockOut($date);
        }
    }

    public function test_a_pending_full_day_special_leave_lets_the_grant_pass_the_attendance_rate(): void
    {
        // 4日目(7日×3日前)を特別休暇の全休で申請中にする → 出勤率4/5=80%で付与される。
        $this->submitSpecialLeave($this->workingDates()[3]);

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave(self::TODAY));

        $this->assertCount(1, $grantedIds);
    }

    public function test_a_returned_special_leave_is_not_counted_so_the_grant_is_skipped(): void
    {
        $requestId = $this->submitSpecialLeave($this->workingDates()[3]);
        app(CommandBus::class)->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdOf($requestId),
            returnedByUserId: $this->approver->id,
            comment: '日付を見直してください',
        ));

        // 差戻しの休暇は出勤扱いにならない → 3/5=60%で付与されない。
        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave(self::TODAY));

        $this->assertCount(0, $grantedIds);
    }

    /** 今日から7日おきの所定労働日(5日)。 */
    private function workingDates(): array
    {
        $today = Carbon::parse(self::TODAY);

        return array_map(fn (int $i) => $today->copy()->subDays($i * 7)->toDateString(), range(0, 4));
    }

    private function clockOut(string $date): void
    {
        AttendanceDayAggregate::retrieve((string) Str::uuid())->create(
            userId: $this->employee->id,
            workDate: $date,
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
    }

    /** 特別休暇の全休を申請し、申請IDを返す。 */
    private function submitSpecialLeave(string $date): string
    {
        return (string) $this->actingAs($this->employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $this->type->id,
            'target_date' => $date,
            'leave_type' => 'full',
            'approver_user_id' => $this->approver->id,
            'reason' => '誕生日のため',
        ])->assertCreated()->json('id');
    }

    private function workflowRequestIdOf(string $requestId): string
    {
        return (string) WorkflowRequest::query()
            ->where('subject_type', 'special_leave_request')
            ->where('subject_id', $requestId)
            ->value('id');
    }

    private function createWorkStyle(): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }
}
