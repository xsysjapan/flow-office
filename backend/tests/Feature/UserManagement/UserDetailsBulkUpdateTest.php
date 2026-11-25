<?php

namespace Tests\Feature\UserManagement;

use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Commands\SetSpecialLeaveAutoGrantEnabled;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Commands\SetUserTerminationDate;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 利用者詳細のまとめて保存 `PUT /users/{id}/details`
 * (docs/changesets/20261125-save-all-user-and-role/spec.md 論点2・論点4)。
 */
class UserDetailsBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = User::factory()->create();
        $this->assignRole($this->hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function eventCount(User $user, string $eventClass): int
    {
        return DB::table('stored_events')->where('aggregate_uuid', $user->id)->where('event_class', $eventClass)->count();
    }

    private function totalEventCount(User $user): int
    {
        return DB::table('stored_events')->where('aggregate_uuid', $user->id)->count();
    }

    private function putDetails(User $target, array $body)
    {
        return $this->actingAs($this->hr)->putJson("/api/users/{$target->id}/details", $body);
    }

    public function test_only_the_sent_items_are_dispatched_and_recorded(): void
    {
        $target = User::factory()->create(['name' => '変更前', 'hire_date' => '2024-04-01']);

        $response = $this->putDetails($target, [
            'profile' => ['name' => '変更後', 'department' => '開発部'],
            'usage_start_date' => '2024-05-01',
            'special_leave_auto_grant_enabled' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('id', $target->id);
        $response->assertJsonPath('name', '変更後');
        $response->assertJsonPath('department', '開発部');
        $response->assertJsonPath('usage_start_date', '2024-05-01');
        $response->assertJsonPath('special_leave_auto_grant_enabled', false);
        $response->assertJsonPath('hire_date', '2024-04-01');

        $this->assertSame(1, $this->eventCount($target, 'user.profile_updated'));
        $this->assertSame(1, $this->eventCount($target, 'user.usage_start_date_set'));
        $this->assertSame(1, $this->eventCount($target, 'user.special_leave_auto_grant_enabled_set'));
        // 送っていない項目のイベントは記録されない。
        $this->assertSame(0, $this->eventCount($target, 'user.hire_date_set'));
        $this->assertSame(0, $this->eventCount($target, 'user.termination_date_set'));
        $this->assertSame(0, $this->eventCount($target, 'user.paid_leave_auto_grant_enabled_set'));
    }

    public function test_an_empty_body_records_nothing(): void
    {
        $target = User::factory()->create();
        $before = $this->totalEventCount($target);

        $this->putDetails($target, [])->assertOk();

        $this->assertSame($before, $this->totalEventCount($target));
    }

    public function test_display_name_is_accepted_as_an_alias_of_name(): void
    {
        $target = User::factory()->create(['name' => '変更前']);

        $this->putDetails($target, ['profile' => ['display_name' => '別名で変更']])->assertOk()->assertJsonPath('name', '別名で変更');
    }

    public function test_termination_date_can_be_cleared_with_null(): void
    {
        $target = User::factory()->create(['hire_date' => '2024-04-01', 'termination_date' => '2026-03-31']);

        $this->putDetails($target, ['termination_date' => null])->assertOk()->assertJsonPath('termination_date', null);

        $this->assertNull($target->refresh()->termination_date);
        $this->assertSame(1, $this->eventCount($target, 'user.termination_date_set'));
    }

    public function test_a_command_rejection_rolls_back_everything_and_maps_to_the_item_key(): void
    {
        $target = User::factory()->create(['name' => '変更前', 'hire_date' => '2024-04-01', 'termination_date' => null]);
        $before = $this->totalEventCount($target);
        // 退社日のコマンドが業務の規則で拒否する状況を作る。
        config(['domain.command_handlers.'.SetUserTerminationDate::class => RejectingCommandHandler::class]);

        $response = $this->putDetails($target, [
            'profile' => ['name' => '変更後'],
            'termination_date' => '2026-03-31',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['termination_date']);
        $this->assertSame('変更前', $target->refresh()->name);
        $this->assertNull($target->termination_date);
        $this->assertSame($before, $this->totalEventCount($target));
    }

    public function test_a_profile_rejection_by_the_command_maps_to_the_profile_key(): void
    {
        // 最後のシステム管理者は無効化できない。
        $adminGroupId = (string) \Illuminate\Support\Str::uuid();
        $typeId = DB::table('group_types')->insertGetId(['code' => 'T_ADM', 'name' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('groups')->insert(['id' => $adminGroupId, 'group_type_id' => $typeId, 'name' => 'admin', 'code' => 'SYSTEM_ADMINISTRATORS', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $target = User::factory()->create(['account_status' => 'active']);
        DB::table('memberships')->insert(['user_id' => $target->id, 'group_id' => $adminGroupId, 'membership_kind' => 'member', 'created_at' => now(), 'updated_at' => now()]);

        $this->putDetails($target, ['profile' => ['account_status' => 'disabled']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile.account_status']);

        $this->assertSame('active', $target->refresh()->account_status);
    }

    public function test_both_dates_moved_forward_past_the_current_termination_date_change_termination_first(): void
    {
        $target = User::factory()->create(['hire_date' => '2024-04-01', 'termination_date' => '2026-03-31']);

        // 新しい入社日(2026-06-01)が今の退社日(2026-03-31)より後。入社日を先にすると途中の状態が不正になる。
        $this->putDetails($target, ['hire_date' => '2026-06-01', 'termination_date' => '2027-03-31'])
            ->assertOk()
            ->assertJsonPath('hire_date', '2026-06-01')
            ->assertJsonPath('termination_date', '2027-03-31');

        $events = DB::table('stored_events')->where('aggregate_uuid', $target->id)
            ->whereIn('event_class', ['user.hire_date_set', 'user.termination_date_set'])
            ->orderBy('id')->pluck('event_class')->all();
        $this->assertSame(['user.termination_date_set', 'user.hire_date_set'], $events);
    }

    public function test_both_dates_moved_backward_change_hire_first(): void
    {
        $target = User::factory()->create(['hire_date' => '2024-04-01', 'termination_date' => '2026-03-31']);

        // 新しい退社日(2021-03-31)が今の入社日(2024-04-01)より前。退社日を先にすると途中の状態が不正になる。
        $this->putDetails($target, ['hire_date' => '2020-04-01', 'termination_date' => '2021-03-31'])
            ->assertOk()
            ->assertJsonPath('hire_date', '2020-04-01')
            ->assertJsonPath('termination_date', '2021-03-31');

        $events = DB::table('stored_events')->where('aggregate_uuid', $target->id)
            ->whereIn('event_class', ['user.hire_date_set', 'user.termination_date_set'])
            ->orderBy('id')->pluck('event_class')->all();
        $this->assertSame(['user.hire_date_set', 'user.termination_date_set'], $events);
    }

    public function test_an_invalid_final_state_is_rejected_and_nothing_is_saved(): void
    {
        $target = User::factory()->create(['name' => '変更前', 'hire_date' => '2024-04-01', 'termination_date' => '2026-03-31']);
        $before = $this->totalEventCount($target);

        $this->putDetails($target, ['profile' => ['name' => '変更後'], 'hire_date' => '2027-01-01', 'termination_date' => '2026-12-31'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['termination_date']);

        $this->putDetails($target, ['hire_date' => '2026-04-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hire_date']);

        $this->putDetails($target, ['termination_date' => '2024-03-31'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['termination_date']);

        $target->refresh();
        $this->assertSame('変更前', $target->name);
        $this->assertSame('2024-04-01', $target->hire_date->toDateString());
        $this->assertSame('2026-03-31', $target->termination_date->toDateString());
        $this->assertSame($before, $this->totalEventCount($target));
    }

    public function test_validation_errors_use_the_item_keys(): void
    {
        $target = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->putDetails($target, [
            'profile' => ['email' => 'taken@example.com', 'account_status' => 'unknown'],
            'hire_date' => 'not-a-date',
            'paid_leave_auto_grant_enabled' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors([
            'profile.email', 'profile.account_status', 'hire_date', 'paid_leave_auto_grant_enabled',
        ]);
    }

    public function test_external_hr_items_are_rejected_with_their_item_keys(): void
    {
        $target = User::factory()->create(['name' => '変更前', 'hire_date' => '2024-04-01']);
        foreach (['display_name', 'hire_date'] as $field) {
            DB::table('field_authorities')->insert(['field_key' => $field, 'authority_type' => 'EXTERNAL_HR', 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = $this->totalEventCount($target);

        $this->putDetails($target, [
            'profile' => ['name' => '変更後', 'department' => '開発部'],
            'hire_date' => '2024-05-01',
            'usage_start_date' => '2024-06-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['profile.name', 'hire_date'])
            ->assertJsonMissingValidationErrors(['profile.department', 'usage_start_date']);

        $this->assertSame('変更前', $target->refresh()->name);
        $this->assertSame($before, $this->totalEventCount($target));
    }

    public function test_a_user_without_permission_gets_403(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create(['name' => '変更前']);

        $this->actingAs($employee)->putJson("/api/users/{$other->id}/details", ['profile' => ['name' => '変更後']])->assertForbidden();

        $this->assertSame('変更前', $other->refresh()->name);
    }

    public function test_schedule_recalculation_reactor_runs_in_the_same_transaction(): void
    {
        Carbon::setTestNow('2026-10-21');
        $admin = User::factory()->create();
        $bus = app(\App\Domain\EventSourcing\CommandBus::class);
        $workStyle = $bus->dispatch(new CreateWorkStyle(
            attributes: [
                'code' => 'FIVE', 'name' => 'FIVE',
                'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
                'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
                'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
                'weekly_scheduled_days' => 5,
                'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
                'is_shift_based' => false,
            ],
            createdByUserId: $admin->id,
        ));
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => 'FIVE', 'work_style_id' => $workStyle->id, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY, 'is_active' => true,
        ]);
        foreach ([6 => 10, 18 => 11, 30 => 12, 42 => 14] as $months => $days) {
            $rule->steps()->create(['grant_category' => 'normal', 'continuous_service_months' => $months, 'grant_days' => $days]);
        }
        $target = User::factory()->create();
        $bus->dispatch(new AssignUserWorkStyleForMonth(userId: $target->id, yearMonth: '2026-10', workStyleId: $workStyle->id, assignedByUserId: $admin->id));
        $bus->dispatch(new SetUserHireDate(userId: $target->id, hireDate: '2024-01-01', changedByUserId: $admin->id));
        $activeEntries = fn () => PaidLeaveScheduleEntry::query()->where('user_id', $target->id)
            ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)->get();
        $scheduleEvents = fn () => DB::table('stored_events')->where('event_class', 'like', 'paid_leave_schedule.%')->count();
        $this->assertSame('2027-07-01', $activeEntries()->sole()->scheduled_on->toDateString());

        // 後続の項目が拒否されると、入社日の変更も付与予定の再計算も戻る。
        $baseline = $scheduleEvents();
        config(['domain.command_handlers.'.SetSpecialLeaveAutoGrantEnabled::class => RejectingCommandHandler::class]);
        $this->putDetails($target, ['hire_date' => '2025-02-01', 'special_leave_auto_grant_enabled' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['special_leave_auto_grant_enabled']);
        $this->assertSame('2024-01-01', $target->refresh()->hire_date->toDateString());
        $this->assertSame($baseline, $scheduleEvents());
        $this->assertSame('2027-07-01', $activeEntries()->sole()->scheduled_on->toDateString());

        // 成功すると、同じリクエストの中で付与予定が再計算される。
        config(['domain.command_handlers.'.SetSpecialLeaveAutoGrantEnabled::class => \App\Domain\UserManagement\Handlers\SetSpecialLeaveAutoGrantEnabledHandler::class]);
        $this->putDetails($target, ['hire_date' => '2025-02-01'])->assertOk();
        $this->assertGreaterThan($baseline, $scheduleEvents());
        $this->assertSame('2027-08-01', $activeEntries()->sole()->scheduled_on->toDateString());
    }
}

/**
 * 業務の規則で拒否するコマンドを再現するテスト用のハンドラ。
 *
 * @implements CommandHandler<Command>
 */
class RejectingCommandHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        throw new DomainRuleException('テスト用の業務ルール違反です。');
    }
}
