<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UC-C013: 複数従業員予定の一括操作(プレビュー→確定適用→取消)。
 */
class CalendarBulkOperationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function makeWorkStyle(): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00', 'default_break_minutes' => 60,
            'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    public function test_skip_existing_leaves_conflicting_days_untouched(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeWorkStyle();
        $employee = User::factory()->create();

        // 2026-07-01(水)は既存行あり(手動編集済み想定)。
        EmployeeCalendarEntry::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => $employee->id, 'work_date' => '2026-07-01',
            'work_style_id' => $workStyle->id, 'day_type' => 'weekday', 'is_working_day' => false, 'schedule_state' => 'OFF',
        ]);

        $payload = [
            'operation_type' => 'calendar_apply',
            'target_scope' => ['user_ids' => [$employee->id], 'work_style_id' => $workStyle->id, 'from' => '2026-07-01', 'to' => '2026-07-02'],
            'conflict_policy' => 'skip_existing',
            'reason' => '7月分一括生成',
        ];

        $preview = $this->actingAs($admin)->postJson('/api/calendar-bulk-operations/preview', $payload);
        $preview->assertOk();
        $this->assertSame(1, $preview->json('conflict_count'));

        $apply = $this->actingAs($admin)->postJson('/api/calendar-bulk-operations', $payload);
        $apply->assertCreated();
        $apply->assertJsonPath('status', 'applied');

        // 既存行(OFF)は上書きされない。
        $this->assertDatabaseHas('employee_calendar_entries', [
            'user_id' => $employee->id, 'work_date' => '2026-07-01 00:00:00', 'schedule_state' => 'OFF',
        ]);
        // 新規の日(7/2木曜)は生成される。
        $this->assertDatabaseHas('employee_calendar_entries', [
            'user_id' => $employee->id, 'work_date' => '2026-07-02 00:00:00', 'schedule_state' => 'WORK', 'bulk_operation_id' => $apply->json('id'),
        ]);
    }

    public function test_overwrite_replaces_the_conflicting_day(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeWorkStyle();
        $employee = User::factory()->create();

        EmployeeCalendarEntry::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => $employee->id, 'work_date' => '2026-07-01',
            'work_style_id' => $workStyle->id, 'day_type' => 'weekday', 'is_working_day' => false, 'schedule_state' => 'OFF',
        ]);

        $payload = [
            'operation_type' => 'calendar_apply',
            'target_scope' => ['user_ids' => [$employee->id], 'work_style_id' => $workStyle->id, 'from' => '2026-07-01', 'to' => '2026-07-01'],
            'conflict_policy' => 'overwrite',
            'reason' => '上書き',
        ];

        $this->actingAs($admin)->postJson('/api/calendar-bulk-operations', $payload)->assertCreated();

        $this->assertDatabaseHas('employee_calendar_entries', [
            'user_id' => $employee->id, 'work_date' => '2026-07-01 00:00:00', 'schedule_state' => 'WORK',
        ]);
    }

    public function test_fail_on_conflict_rejects_the_whole_operation_when_any_conflict_exists(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeWorkStyle();
        $employee = User::factory()->create();

        EmployeeCalendarEntry::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => $employee->id, 'work_date' => '2026-07-01',
            'work_style_id' => $workStyle->id, 'day_type' => 'weekday', 'is_working_day' => false, 'schedule_state' => 'OFF',
        ]);

        $payload = [
            'operation_type' => 'calendar_apply',
            'target_scope' => ['user_ids' => [$employee->id], 'work_style_id' => $workStyle->id, 'from' => '2026-07-01', 'to' => '2026-07-02'],
            'conflict_policy' => 'fail_on_conflict',
            'reason' => '全件失敗確認',
        ];

        $this->actingAs($admin)->postJson('/api/calendar-bulk-operations', $payload)->assertUnprocessable();

        // 7/2にも何も適用されていない(全体不実行)。
        $this->assertDatabaseMissing('employee_calendar_entries', [
            'user_id' => $employee->id, 'work_date' => '2026-07-02 00:00:00',
        ]);
    }

    public function test_revert_restores_the_previous_state(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeWorkStyle();
        $employee = User::factory()->create();

        $payload = [
            'operation_type' => 'calendar_apply',
            'target_scope' => ['user_ids' => [$employee->id], 'work_style_id' => $workStyle->id, 'from' => '2026-07-01', 'to' => '2026-07-01'],
            'conflict_policy' => 'skip_existing',
            'reason' => '新規生成',
        ];
        $bulkOperationId = $this->actingAs($admin)->postJson('/api/calendar-bulk-operations', $payload)->json('id');

        $this->assertDatabaseHas('employee_calendar_entries', [
            'user_id' => $employee->id, 'work_date' => '2026-07-01 00:00:00', 'schedule_state' => 'WORK',
        ]);

        $revert = $this->actingAs($admin)->postJson("/api/calendar-bulk-operations/{$bulkOperationId}/revert");
        $revert->assertOk();
        $revert->assertJsonPath('status', 'reverted');

        // 適用前は行が存在しなかったため、取消後はUNASSIGNEDへ戻る。
        $this->assertDatabaseHas('employee_calendar_entries', [
            'user_id' => $employee->id, 'work_date' => '2026-07-01 00:00:00', 'schedule_state' => 'UNASSIGNED',
        ]);
    }

    public function test_bulk_edit_sets_schedule_state_directly(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeWorkStyle();
        $employee = User::factory()->create();

        $payload = [
            'operation_type' => 'bulk_edit',
            'target_scope' => ['entries' => [
                ['user_id' => $employee->id, 'work_date' => '2026-07-10', 'schedule_state' => 'LEAVE', 'entry_type' => 'MANUAL_ADJUSTMENT', 'work_style_id' => $workStyle->id],
            ]],
            'conflict_policy' => 'skip_existing',
            'reason' => '特別休暇の一括反映',
        ];

        $this->actingAs($admin)->postJson('/api/calendar-bulk-operations', $payload)->assertCreated();

        $this->assertDatabaseHas('employee_calendar_entries', [
            'user_id' => $employee->id, 'work_date' => '2026-07-10 00:00:00', 'schedule_state' => 'LEAVE',
        ]);
    }
}
