<?php

namespace Tests\Feature\Attendance;

use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * UC-C004: 3交代制シフトを作成する。
 */
class ShiftPatternTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function makeShiftWorkStyle(?int $maxConsecutiveWorkDays = null): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'shift-3', 'name' => '3交代制', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => true,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
            'max_consecutive_work_days' => $maxConsecutiveWorkDays,
        ]);
    }

    public function test_admin_can_create_a_shift_pattern_and_it_is_recorded_as_an_event(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/shift-patterns', [
            'code' => 'night_shift', 'name' => '深夜勤', 'start_time' => '22:00', 'end_time' => '06:00',
            'crosses_midnight' => true, 'break_minutes' => 60, 'prescribed_work_minutes' => 420,
        ])->assertCreated();

        $patternId = $response->json('id');

        $this->assertDatabaseHas('shift_patterns', ['id' => $patternId, 'code' => 'night_shift']);

        $event = EloquentStoredEvent::query()->where('aggregate_uuid', $patternId)->first();
        $this->assertNotNull($event);
        $this->assertSame('shift_pattern.created', $event->event_class);
    }

    public function test_employee_cannot_create_a_shift_pattern(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/shift-patterns', [
            'code' => 'day_shift', 'name' => '日勤', 'prescribed_work_minutes' => 480,
        ])->assertForbidden();
    }

    public function test_assigning_an_overnight_pattern_computes_datetimes_across_midnight_and_stays_draft(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeShiftWorkStyle();
        $employee = User::factory()->create();

        $pattern = $this->actingAs($admin)->postJson('/api/shift-patterns', [
            'code' => 'night_shift', 'name' => '深夜勤', 'start_time' => '22:00', 'end_time' => '06:00',
            'crosses_midnight' => true, 'break_minutes' => 60, 'prescribed_work_minutes' => 420,
        ])->assertCreated()->json();

        $response = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/assign-pattern', [
            'user_id' => $employee->id, 'work_style_id' => $workStyle->id, 'work_date' => '2026-08-10',
            'shift_pattern_id' => $pattern['id'],
        ])->assertCreated();

        $response->assertJsonPath('shift_pattern_id', $pattern['id']);
        $response->assertJsonPath('is_working_day', true);
        $response->assertJsonPath('is_published', false);
        $this->assertSame('2026-08-10T22:00:00+00:00', $response->json('planned_start_at'));
        $this->assertSame('2026-08-11T06:00:00+00:00', $response->json('planned_end_at'));

        $assignment = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->firstOrFail();
        $event = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $assignment->id)
            ->where('event_class', 'employee_shift.assigned')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($pattern['id'], $event->event_properties['shiftPatternId']);
    }

    public function test_assigning_a_pattern_with_a_break_window_reflects_it_on_the_assignment(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeShiftWorkStyle();
        $employee = User::factory()->create();

        $pattern = $this->actingAs($admin)->postJson('/api/shift-patterns', [
            'code' => 'day_shift', 'name' => '日勤', 'start_time' => '09:00', 'end_time' => '18:00',
            'break_minutes' => 60, 'break_start_time' => '12:00', 'break_end_time' => '13:00',
            'prescribed_work_minutes' => 480,
        ])->assertCreated()->json();
        $this->assertSame('12:00', $pattern['break_start_time']);

        $response = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/assign-pattern', [
            'user_id' => $employee->id, 'work_style_id' => $workStyle->id, 'work_date' => '2026-08-10',
            'shift_pattern_id' => $pattern['id'],
        ])->assertCreated();

        $this->assertSame('2026-08-10T12:00:00+00:00', $response->json('planned_break_start_at'));
        $this->assertSame('2026-08-10T13:00:00+00:00', $response->json('planned_break_end_at'));
    }

    public function test_assigning_a_day_off_pattern_marks_the_day_as_not_working(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeShiftWorkStyle();
        $employee = User::factory()->create();

        $pattern = $this->actingAs($admin)->postJson('/api/shift-patterns', [
            'code' => 'day_off', 'name' => '公休', 'prescribed_work_minutes' => 0,
        ])->assertCreated()->json();

        $response = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/assign-pattern', [
            'user_id' => $employee->id, 'work_style_id' => $workStyle->id, 'work_date' => '2026-08-10',
            'shift_pattern_id' => $pattern['id'], 'is_legal_holiday' => true,
        ])->assertCreated();

        $response->assertJsonPath('is_working_day', false);
        $response->assertJsonPath('is_legal_holiday', true);
        $this->assertNull($response->json('planned_start_at'));
    }

    public function test_review_flags_consecutive_work_days_beyond_the_work_styles_limit(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeShiftWorkStyle(maxConsecutiveWorkDays: 5);
        $employee = User::factory()->create();

        $dayShift = $this->actingAs($admin)->postJson('/api/shift-patterns', [
            'code' => 'day_shift', 'name' => '日勤', 'start_time' => '09:00', 'end_time' => '18:00',
            'break_minutes' => 60, 'prescribed_work_minutes' => 480,
        ])->assertCreated()->json();

        // 2026-08-03(月)〜2026-08-09(日)まで7連勤(上限5日を超える)。
        foreach ($this->datesInRange('2026-08-03', '2026-08-09') as $date) {
            $this->actingAs($admin)->postJson('/api/employee-shift-assignments/assign-pattern', [
                'user_id' => $employee->id, 'work_style_id' => $workStyle->id, 'work_date' => $date,
                'shift_pattern_id' => $dayShift['id'],
            ])->assertCreated();
        }

        $review = $this->actingAs($admin)->getJson(
            '/api/employee-shift-assignments/review?user_ids[]='.$employee->id.'&year_month=2026-08'
        )->assertOk()->json();

        $this->assertCount(1, $review['consecutive_work_violations']);
        $this->assertSame(7, $review['consecutive_work_violations'][0]['consecutive_days']);
        $this->assertSame(5, $review['consecutive_work_violations'][0]['max_allowed']);
    }

    public function test_publish_marks_draft_assignments_as_published_and_does_not_touch_already_published_rows(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeShiftWorkStyle();
        $employee = User::factory()->create();

        $dayShift = $this->actingAs($admin)->postJson('/api/shift-patterns', [
            'code' => 'day_shift', 'name' => '日勤', 'start_time' => '09:00', 'end_time' => '18:00',
            'break_minutes' => 60, 'prescribed_work_minutes' => 480,
        ])->assertCreated()->json();

        $this->actingAs($admin)->postJson('/api/employee-shift-assignments/assign-pattern', [
            'user_id' => $employee->id, 'work_style_id' => $workStyle->id, 'work_date' => '2026-08-10',
            'shift_pattern_id' => $dayShift['id'],
        ])->assertCreated();

        $assignment = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertFalse($assignment->is_published);

        $result = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/publish', [
            'user_ids' => [$employee->id], 'year_month' => '2026-08',
        ])->assertOk()->json();

        $this->assertSame(1, $result['published_count']);
        $this->assertTrue($assignment->refresh()->is_published);

        $publishedEvent = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $assignment->id)
            ->where('event_class', 'employee_shift.published')
            ->first();
        $this->assertNotNull($publishedEvent);

        // 再度publishしても、既にpublished済みの行は対象にならない。
        $result2 = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/publish', [
            'user_ids' => [$employee->id], 'year_month' => '2026-08',
        ])->assertOk()->json();
        $this->assertSame(0, $result2['published_count']);
    }

    /**
     * @return list<string>
     */
    private function datesInRange(string $from, string $to): array
    {
        $dates = [];
        $cursor = strtotime($from);
        $end = strtotime($to);

        while ($cursor <= $end) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        return $dates;
    }
}
