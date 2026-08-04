<?php

namespace Tests\Feature\ShiftSwap;

use App\Models\EmployeeShiftAssignment;
use App\Models\ShiftSwapRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 振替休日を申請する / 承認する。特別休暇(SpecialLeaveRequestTest)と同じ申請・承認の
 * 流れだが、ビジネスロジックは独立したApp\Domain\ShiftSwapとして実装されている。
 *
 * 対象週は常に月曜始まり(week_starts_on=1)の 2026-08-10(月)〜2026-08-16(日) を使う。
 */
class ShiftSwapRequestTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkStyle(
        string $workTimeSystem = WorkStyle::WORK_TIME_SYSTEM_FIXED,
        string $legalHolidayRule = WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
    ): WorkStyle {
        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度-'.Str::random(6), 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);

        return WorkStyle::query()->create([
            'code' => 'fixed-'.Str::random(8), 'name' => '固定勤務', 'work_time_system' => $workTimeSystem,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
            'legal_holiday_rule' => $legalHolidayRule,
        ]);
    }

    private function createWorkdayAssignment(User $user, WorkStyle $workStyle, string $date, int $minutes = 480): EmployeeShiftAssignment
    {
        $endTime = date('H:i:s', strtotime('09:00:00') + $minutes * 60);

        return EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} {$endTime}",
            'planned_break_minutes' => 0,
        ]);
    }

    private function createLegalHolidayAssignment(User $user, WorkStyle $workStyle, string $date): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'legal_holiday', 'is_working_day' => false, 'is_legal_holiday' => true, 'is_company_holiday' => false,
            'planned_start_at' => null, 'planned_end_at' => null, 'planned_break_minutes' => 0,
        ]);
    }

    private function createCompanyHolidayAssignment(User $user, WorkStyle $workStyle, string $date): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'company_holiday', 'is_working_day' => false, 'is_legal_holiday' => false, 'is_company_holiday' => true,
            'planned_start_at' => null, 'planned_end_at' => null, 'planned_break_minutes' => 0,
        ]);
    }

    /**
     * 平日(月〜金)を作成する。$minutesPerDayが480(週2400分)なら週40時間ちょうどに達する。
     */
    private function createWeekdays(User $user, WorkStyle $workStyle, int $minutesPerDay = 400): void
    {
        foreach (['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14'] as $date) {
            $this->createWorkdayAssignment($user, $workStyle, $date, $minutesPerDay);
        }
    }

    public function test_a_non_fixed_work_time_system_cannot_request_a_shift_swap(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(workTimeSystem: WorkStyle::WORK_TIME_SYSTEM_MONTHLY_VARIABLE);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-12',
            'approver_user_id' => $approver->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ShiftSwapRequest::query()->count());
    }

    public function test_neither_date_being_a_holiday_is_rejected(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle();
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-10',
            'substitute_date' => '2026-08-17',
            'approver_user_id' => $approver->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_both_dates_being_a_holiday_is_rejected(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle();
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');
        $this->createCompanyHolidayAssignment($employee, $workStyle, '2026-08-17');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-17',
            'approver_user_id' => $approver->id,
        ]);

        $response->assertStatus(422);
    }

    /**
     * target_dateを労働日、substitute_dateを休日にする(旧来と逆方向の)申請も許可される。
     * 週の制約・週40時間判定は、休日である側(substitute_date)を基準に行われる。
     */
    public function test_a_working_day_can_be_set_as_the_target_with_a_holiday_as_the_substitute(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle();
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $requestId = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-12',
            'substitute_date' => '2026-08-16',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/shift-swap/requests/{$requestId}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $target = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-12')->firstOrFail();
        $this->assertFalse((bool) $target->is_working_day);
        $this->assertTrue((bool) $target->is_legal_holiday);

        $substitute = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-16')->firstOrFail();
        $this->assertTrue((bool) $substitute->is_working_day);
        $this->assertFalse((bool) $substitute->is_legal_holiday);
    }

    public function test_a_legal_holiday_under_the_weekly_rule_cannot_be_swapped_to_a_different_week(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-24', // 翌週の月曜日
            'approver_user_id' => $approver->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ShiftSwapRequest::query()->count());
    }

    public function test_a_legal_holiday_under_the_weekly_rule_can_be_swapped_within_the_same_week(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-12', // 同一週の水曜日
            'approver_user_id' => $approver->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'submitted');
    }

    public function test_a_legal_holiday_under_the_four_weeks_four_days_rule_can_be_swapped_to_a_different_week(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-24', // 翌週の月曜日
            'approver_user_id' => $approver->id,
        ]);

        $response->assertCreated();
    }

    public function test_a_request_is_rejected_when_the_target_weeks_planned_hours_already_reach_forty(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        // 月〜金 480分 x 5日 = 2400分(週40時間ちょうど)
        $this->createWeekdays($employee, $workStyle, 480);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-11', // 同一週の火曜日
            'approver_user_id' => $approver->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_approving_swaps_the_target_and_substitute_shift_fields(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');
        // 振替先(2026-08-24)はまだ勤務予定行が展開されていない。

        $requestId = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-24',
            'approver_user_id' => $approver->id,
            'reason' => '私用のため',
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/shift-swap/requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $target = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-16')->firstOrFail();
        $this->assertTrue((bool) $target->is_working_day);
        $this->assertFalse((bool) $target->is_legal_holiday);
        $this->assertFalse((bool) $target->is_company_holiday);
        $this->assertSame('2026-08-16 09:00:00', $target->planned_start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-16 18:00:00', $target->planned_end_at->format('Y-m-d H:i:s'));
        $this->assertTrue((bool) $target->is_manually_overridden);

        $substitute = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-24')->firstOrFail();
        $this->assertFalse((bool) $substitute->is_working_day);
        $this->assertTrue((bool) $substitute->is_legal_holiday);
        $this->assertFalse((bool) $substitute->is_company_holiday);
        $this->assertNull($substitute->planned_start_at);
        $this->assertTrue((bool) $substitute->is_manually_overridden);
    }

    public function test_only_the_designated_approver_can_approve(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $other = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $requestId = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-12',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($other)->postJson("/api/shift-swap/requests/{$requestId}/approve")->assertStatus(422);
    }

    public function test_approver_can_return_a_request_with_a_comment(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $requestId = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-12',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $response = $this->actingAs($approver)->postJson("/api/shift-swap/requests/{$requestId}/return", [
            'comment' => '振替先を再検討してください',
        ]);
        $response->assertOk();
        $response->assertJsonPath('status', 'returned');

        $this->assertSame('振替先を再検討してください', ShiftSwapRequest::query()->findOrFail($requestId)->return_comment);
    }

    public function test_employee_can_cancel_their_own_submitted_request(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $requestId = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-12',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $response = $this->actingAs($employee)->postJson("/api/shift-swap/requests/{$requestId}/cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');

        $workflowRequest = WorkflowRequest::query()->where('subject_id', $requestId)->firstOrFail();
        $this->assertSame('cancelled', $workflowRequest->status);
    }

    /**
     * system_settings.shift_swap_requires_approval=falseの場合、承認ワークフローを経由せず
     * 申請と同時に自動承認・シフト入れ替えまで完結する。
     */
    public function test_when_approval_is_not_required_the_request_is_auto_approved_and_swaps_the_shift(): void
    {
        SystemSetting::current()->update(['shift_swap_requires_approval' => false]);

        $employee = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-12',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'approved');
        $requestId = $response->json('id');

        $this->assertSame(0, WorkflowRequest::query()->where('subject_id', $requestId)->count());

        $target = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-16')->firstOrFail();
        $this->assertTrue((bool) $target->is_working_day);

        $substitute = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-12')->firstOrFail();
        $this->assertFalse((bool) $substitute->is_working_day);
        $this->assertTrue((bool) $substitute->is_legal_holiday);
    }

    /**
     * 所定休日(法定休日ではない)も振替対象にでき、法定休日の同一週制約は適用されない。
     */
    public function test_a_company_holiday_can_be_swapped_to_a_different_week(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        $this->createWeekdays($employee, $workStyle);
        $this->createCompanyHolidayAssignment($employee, $workStyle, '2026-08-15');

        $response = $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-15',
            'substitute_date' => '2026-08-24', // 翌週の月曜日
            'approver_user_id' => $approver->id,
        ]);

        $response->assertCreated();
    }

    public function test_my_requests_and_requests_to_approve_list_the_correct_requests(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->createWorkStyle(legalHolidayRule: WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY);
        $this->createWeekdays($employee, $workStyle);
        $this->createLegalHolidayAssignment($employee, $workStyle, '2026-08-16');

        $this->actingAs($employee)->postJson('/api/shift-swap/requests', [
            'target_date' => '2026-08-16',
            'substitute_date' => '2026-08-12',
            'approver_user_id' => $approver->id,
        ])->assertCreated();

        $this->actingAs($employee)->getJson('/api/shift-swap/requests/mine')->assertOk()->assertJsonCount(1);
        $this->actingAs($approver)->getJson('/api/shift-swap/requests/to-approve')->assertOk()->assertJsonCount(1);
    }
}
