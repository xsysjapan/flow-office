<?php

namespace Tests\Feature\PaidLeave;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrantRule;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 有給付与ルールの対象社員一覧 (docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。
 */
class PaidLeaveGrantRuleTargetUsersTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkStyle(string $name = '通常勤務'): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => $name, 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    public function test_returns_all_users_with_hire_date_when_rule_has_no_work_style_restriction(): void
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社員', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);
        $eligible = User::factory()->create(['name' => '対象太郎', 'hire_date' => '2024-04-01', 'paid_leave_auto_grant_enabled' => false]);
        User::factory()->create(['name' => '未入社花子', 'hire_date' => null]);

        $response = $this->actingAs($eligible)->getJson("/api/paid-leave/grant-rules/{$rule->id}/target-users");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('対象太郎'));
        $this->assertFalse($names->contains('未入社花子'));
        $entry = collect($response->json('data'))->firstWhere('id', $eligible->id);
        $this->assertFalse($entry['paid_leave_auto_grant_enabled']);
    }

    public function test_restricts_to_users_currently_assigned_to_the_rules_work_style(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10'));
        try {
            $workStyle = $this->createWorkStyle('シフト勤務');
            $otherWorkStyle = $this->createWorkStyle('通常勤務');
            $rule = PaidLeaveGrantRule::query()->create([
                'name' => 'シフト勤務者向け', 'work_style_id' => $workStyle->id, 'min_attendance_rate' => 80,
                'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
            ]);
            $matching = User::factory()->create(['hire_date' => '2024-04-01']);
            $nonMatching = User::factory()->create(['hire_date' => '2024-04-01']);
            EmployeeCalendarEntry::query()->create([
                'user_id' => $matching->id, 'work_date' => '2026-08-10', 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_break_minutes' => 60,
            ]);
            EmployeeCalendarEntry::query()->create([
                'user_id' => $nonMatching->id, 'work_date' => '2026-08-10', 'work_style_id' => $otherWorkStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_break_minutes' => 60,
            ]);

            $response = $this->actingAs($matching)->getJson("/api/paid-leave/grant-rules/{$rule->id}/target-users");

            $response->assertOk();
            $ids = collect($response->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($matching->id));
            $this->assertFalse($ids->contains($nonMatching->id));
            $this->assertSame('シフト勤務', $response->json('data')[0]['work_style']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
