<?php

namespace Tests\Feature;

use App\Domain\Attendance\Commands\GenerateRotationShiftAssignments;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\ShiftPattern;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 交代制勤務のローテーション自動生成(指示書 8.4節〜8.8節)。
 * A勤・B勤・C勤・休を1つの働き方の中のローテーションパターンとして管理し、
 * 社員個別の開始日・開始位置から日別の勤務予定を機械的に生成する。
 */
class RotationShiftTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function makeShiftBasedWorkStyle(): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'rotation-'.uniqid(), 'name' => '工場3交代制', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => true,
        ]);
    }

    /**
     * @return array{a: ShiftPattern, b: ShiftPattern, c: ShiftPattern, off: ShiftPattern}
     */
    private function makeShiftPatterns(): array
    {
        return [
            'a' => ShiftPattern::query()->create([
                'code' => 'a-shift', 'name' => 'A勤', 'start_time' => '06:00', 'end_time' => '14:00',
                'crosses_midnight' => false, 'break_minutes' => 45, 'prescribed_work_minutes' => 435,
            ]),
            'b' => ShiftPattern::query()->create([
                'code' => 'b-shift', 'name' => 'B勤', 'start_time' => '14:00', 'end_time' => '22:00',
                'crosses_midnight' => false, 'break_minutes' => 45, 'prescribed_work_minutes' => 435,
            ]),
            'c' => ShiftPattern::query()->create([
                'code' => 'c-shift', 'name' => 'C勤', 'start_time' => '22:00', 'end_time' => '06:00',
                'crosses_midnight' => true, 'break_minutes' => 45, 'prescribed_work_minutes' => 435,
            ]),
            'off' => ShiftPattern::query()->create([
                'code' => 'off', 'name' => '休日', 'start_time' => null, 'end_time' => null,
                'crosses_midnight' => false, 'break_minutes' => 0, 'prescribed_work_minutes' => 0,
            ]),
        ];
    }

    /**
     * @return array{work_style: WorkStyle, patterns: array, rotation_pattern_id: int}
     */
    private function createRotationPattern(User $admin): array
    {
        $workStyle = $this->makeShiftBasedWorkStyle();
        $patterns = $this->makeShiftPatterns();

        // [A, A, 休, B, B, 休, C, C, 休] の9日周期。
        $items = [
            ['sequence' => 0, 'shift_pattern_id' => $patterns['a']->id],
            ['sequence' => 1, 'shift_pattern_id' => $patterns['a']->id],
            ['sequence' => 2, 'shift_pattern_id' => $patterns['off']->id],
            ['sequence' => 3, 'shift_pattern_id' => $patterns['b']->id],
            ['sequence' => 4, 'shift_pattern_id' => $patterns['b']->id],
            ['sequence' => 5, 'shift_pattern_id' => $patterns['off']->id],
            ['sequence' => 6, 'shift_pattern_id' => $patterns['c']->id],
            ['sequence' => 7, 'shift_pattern_id' => $patterns['c']->id],
            ['sequence' => 8, 'shift_pattern_id' => $patterns['off']->id],
        ];

        $response = $this->actingAs($admin)->postJson('/api/rotation-patterns', [
            'work_style_id' => $workStyle->id,
            'name' => '2交代3班ローテーション',
            'items' => $items,
        ])->assertCreated();

        return [
            'work_style' => $workStyle,
            'patterns' => $patterns,
            'rotation_pattern_id' => $response->json('id'),
        ];
    }

    public function test_a_rotation_pattern_can_only_be_created_for_a_shift_based_work_style(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => false,
        ]);
        $pattern = ShiftPattern::query()->create([
            'code' => 'x', 'name' => 'X', 'crosses_midnight' => false, 'break_minutes' => 0, 'prescribed_work_minutes' => 480,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/rotation-patterns', [
            'work_style_id' => $workStyle->id,
            'name' => '不正なローテーション',
            'items' => [['sequence' => 0, 'shift_pattern_id' => $pattern->id]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_style_id');
    }

    public function test_creating_a_rotation_pattern_with_non_contiguous_sequences_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeShiftBasedWorkStyle();
        $patterns = $this->makeShiftPatterns();

        $response = $this->actingAs($admin)->postJson('/api/rotation-patterns', [
            'work_style_id' => $workStyle->id,
            'name' => '不正な周期',
            'items' => [
                ['sequence' => 0, 'shift_pattern_id' => $patterns['a']->id],
                ['sequence' => 2, 'shift_pattern_id' => $patterns['b']->id],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_creating_a_rotation_pattern_derives_the_cycle_length_from_the_items(): void
    {
        $admin = $this->makeAdmin();
        $result = $this->createRotationPattern($admin);

        $response = $this->actingAs($admin)->getJson('/api/rotation-patterns');

        $response->assertOk();
        $pattern = collect($response->json())->firstWhere('id', $result['rotation_pattern_id']);
        $this->assertSame(9, $pattern['cycle_length']);
        $this->assertCount(9, $pattern['items']);
    }

    public function test_assigning_a_rotation_beyond_the_cycle_length_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $result = $this->createRotationPattern($admin);
        $employee = User::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/employee-rotation-assignments', [
            'user_id' => $employee->id,
            'rotation_pattern_id' => $result['rotation_pattern_id'],
            'rotation_start_date' => '2026-08-01',
            'rotation_start_position' => 9,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('rotation_start_position');
    }

    public function test_the_preview_endpoint_expands_the_rotation_without_persisting_anything(): void
    {
        $admin = $this->makeAdmin();
        $result = $this->createRotationPattern($admin);

        $response = $this->actingAs($admin)->postJson("/api/rotation-patterns/{$result['rotation_pattern_id']}/preview", [
            'rotation_start_date' => '2026-08-01',
            'rotation_start_position' => 0,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
        ]);

        $response->assertOk();
        $days = $response->json('days');
        $this->assertSame('a-shift', $days[0]['shift_pattern_code']);
        $this->assertSame('a-shift', $days[1]['shift_pattern_code']);
        $this->assertSame('off', $days[2]['shift_pattern_code']);
        $this->assertSame('b-shift', $days[3]['shift_pattern_code']);
        $this->assertSame('off', $days[8]['shift_pattern_code']);
        $this->assertSame(0, EmployeeShiftAssignment::query()->count());
    }

    public function test_generating_shifts_from_a_rotation_matches_the_expected_cycle(): void
    {
        $admin = $this->makeAdmin();
        $result = $this->createRotationPattern($admin);
        $employee = User::factory()->create();

        $this->actingAs($admin)->postJson('/api/employee-rotation-assignments', [
            'user_id' => $employee->id,
            'rotation_pattern_id' => $result['rotation_pattern_id'],
            'rotation_start_date' => '2026-08-01',
            'rotation_start_position' => 0,
        ])->assertCreated();

        $response = $this->actingAs($admin)->postJson('/api/employee-rotation-assignments/generate', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
        ]);

        $response->assertOk();
        $this->assertSame(9, $response->json('generated_count'));
        $this->assertEmpty($response->json('skipped_dates'));

        $assignments = EmployeeShiftAssignment::query()->where('user_id', $employee->id)->orderBy('work_date')->get();
        $this->assertSame('a-shift', $assignments[0]->day_type);
        $this->assertSame('a-shift', $assignments[1]->day_type);
        $this->assertSame('off', $assignments[2]->day_type);
        $this->assertFalse($assignments[2]->is_working_day);
        $this->assertTrue($assignments[2]->is_company_holiday);
        $this->assertSame('c-shift', $assignments[6]->day_type);
        // C勤(22:00〜翌06:00)は日跨ぎのため終了時刻が翌日になっている。
        $this->assertSame('2026-08-07', $assignments[6]->planned_start_at->toDateString());
        $this->assertSame('2026-08-08', $assignments[6]->planned_end_at->toDateString());
    }

    public function test_regenerating_skips_manually_overridden_days_by_default(): void
    {
        $admin = $this->makeAdmin();
        $result = $this->createRotationPattern($admin);
        $employee = User::factory()->create();

        $this->actingAs($admin)->postJson('/api/employee-rotation-assignments', [
            'user_id' => $employee->id,
            'rotation_pattern_id' => $result['rotation_pattern_id'],
            'rotation_start_date' => '2026-08-01',
            'rotation_start_position' => 0,
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/employee-rotation-assignments/generate', [
            'user_id' => $employee->id, 'from' => '2026-08-01', 'to' => '2026-08-09',
        ])->assertOk();

        // 8月3日(休の予定)を有給に個別上書きする。
        $this->actingAs($admin)->postJson('/api/employee-shift-assignments/assign-pattern', [
            'user_id' => $employee->id,
            'work_style_id' => $result['work_style']->id,
            'work_date' => '2026-08-03',
            'shift_pattern_id' => $result['patterns']['off']->id,
            'is_company_holiday' => false,
        ])->assertCreated();

        $response = $this->actingAs($admin)->postJson('/api/employee-rotation-assignments/generate', [
            'user_id' => $employee->id, 'from' => '2026-08-01', 'to' => '2026-08-09',
        ]);

        $response->assertOk();
        $this->assertSame(['2026-08-03'], $response->json('skipped_dates'));

        $overridden = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-03')->firstOrFail();
        $this->assertFalse($overridden->is_company_holiday, '個別上書きした内容が保持されている');
    }

    public function test_overwrite_all_mode_regenerates_manually_overridden_days_that_have_no_actual_attendance(): void
    {
        $admin = $this->makeAdmin();
        $result = $this->createRotationPattern($admin);
        $employee = User::factory()->create();

        $this->actingAs($admin)->postJson('/api/employee-rotation-assignments', [
            'user_id' => $employee->id,
            'rotation_pattern_id' => $result['rotation_pattern_id'],
            'rotation_start_date' => '2026-08-01',
            'rotation_start_position' => 0,
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/employee-rotation-assignments/generate', [
            'user_id' => $employee->id, 'from' => '2026-08-01', 'to' => '2026-08-09',
        ])->assertOk();

        $this->actingAs($admin)->postJson('/api/employee-shift-assignments/assign-pattern', [
            'user_id' => $employee->id,
            'work_style_id' => $result['work_style']->id,
            'work_date' => '2026-08-03',
            'shift_pattern_id' => $result['patterns']['off']->id,
            'is_company_holiday' => false,
        ])->assertCreated();

        $response = $this->actingAs($admin)->postJson('/api/employee-rotation-assignments/generate', [
            'user_id' => $employee->id, 'from' => '2026-08-01', 'to' => '2026-08-09',
            'overwrite_mode' => GenerateRotationShiftAssignments::OVERWRITE_MODE_OVERWRITE_ALL,
        ]);

        $response->assertOk();
        $this->assertEmpty($response->json('skipped_dates'));

        $regenerated = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-03')->firstOrFail();
        $this->assertTrue($regenerated->is_company_holiday, 'overwrite_allでは個別上書きも再生成される');
        $this->assertFalse($regenerated->is_manually_overridden);
    }

    public function test_days_with_actual_attendance_are_always_skipped_even_in_overwrite_all_mode(): void
    {
        $admin = $this->makeAdmin();
        $result = $this->createRotationPattern($admin);
        $employee = User::factory()->create();

        $this->actingAs($admin)->postJson('/api/employee-rotation-assignments', [
            'user_id' => $employee->id,
            'rotation_pattern_id' => $result['rotation_pattern_id'],
            'rotation_start_date' => '2026-08-01',
            'rotation_start_position' => 0,
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/employee-rotation-assignments/generate', [
            'user_id' => $employee->id, 'from' => '2026-08-01', 'to' => '2026-08-09',
        ])->assertOk();

        $shift = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-01')->firstOrFail();
        AttendanceDay::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-08-01', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::CLOCKED_OUT, 'source' => 'manual', 'utc_offset_minutes' => 540,
            'actual_start_at' => '2026-08-01 06:00:00', 'actual_end_at' => '2026-08-01 14:00:00',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/employee-rotation-assignments/generate', [
            'user_id' => $employee->id, 'from' => '2026-08-01', 'to' => '2026-08-09',
            'overwrite_mode' => GenerateRotationShiftAssignments::OVERWRITE_MODE_OVERWRITE_ALL,
        ]);

        $response->assertOk();
        $this->assertSame(['2026-08-01'], $response->json('skipped_dates'));
    }

    public function test_generating_without_an_assigned_rotation_fails(): void
    {
        $admin = $this->makeAdmin();
        $employee = User::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/employee-rotation-assignments/generate', [
            'user_id' => $employee->id, 'from' => '2026-08-01', 'to' => '2026-08-09',
        ]);

        $response->assertStatus(422);
    }
}
