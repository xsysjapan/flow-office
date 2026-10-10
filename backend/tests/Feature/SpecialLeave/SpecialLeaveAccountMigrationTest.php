<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeaveAccount\Commands\RegisterSpecialLeaveGrant;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 既存の特別休暇データ(本変更前の付与・消化記録)を利用者の口座へ引き継ぐ運用コマンド
 * (special-leave:migrate-to-account)のテスト。試し実行は書き込まない・--applyで一度だけ引き継ぐ・
 * 引き継ぎ後の新しい申請の承認・取消が移行された付与を使う、を確認する。
 */
class SpecialLeaveAccountMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SpecialLeaveTestHelpers;

    private function workingDays(User $user, array $dates): void
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
            EmployeeCalendarEntry::query()->create([
                'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
                'planned_break_minutes' => 60,
            ]);
        }
    }

    private function migratedEvents(): int
    {
        return EloquentStoredEvent::query()->where('event_class', 'special_leave_account.migrated')->count();
    }

    public function test_dry_run_writes_nothing_and_apply_migrates_the_existing_balance_once(): void
    {
        $employee = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => '旧特別休暇', 'is_active' => true, 'requires_grant' => true]);

        // 本変更前の状態: 付与の行と承認済みの申請・消化記録が直接の行として存在する(口座の記録は無い)。
        $grantId = (string) Str::uuid();
        SpecialLeaveGrant::query()->create([
            'id' => $grantId, 'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
            'used_days' => 1, 'remaining_days' => 2, 'status' => 'active',
        ]);
        $requestId = (string) Str::uuid();
        SpecialLeaveRequest::query()->create([
            'id' => $requestId, 'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'approver_user_id' => $employee->id, 'status' => 'approved', 'leave_type' => 'full',
            'target_date' => '2026-08-10', 'hours' => null, 'requested_days' => 1,
            'submitted_at' => now(), 'approved_at' => now(),
        ]);
        SpecialLeaveUsage::query()->create([
            'user_id' => $employee->id, 'attendance_day_id' => null, 'special_leave_grant_id' => $grantId,
            'special_leave_request_id' => $requestId, 'used_on' => '2026-08-10', 'used_days' => 1,
            'used_minutes' => null, 'usage_type' => 'full', 'is_confirmed' => true,
        ]);

        $this->artisan('special-leave:migrate-to-account')->assertSuccessful();
        $this->assertSame(0, $this->migratedEvents());
        $this->assertNull(SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->firstOrFail()->usage_id);

        $this->artisan('special-leave:migrate-to-account', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, $this->migratedEvents());

        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);
        $this->assertSame(2.0, (float) $grant->remaining_days);
        $usage = SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->firstOrFail();
        $this->assertNotNull($usage->usage_id);
        $this->assertTrue((bool) $usage->is_confirmed);

        // 2回目は口座に記録がある利用者を対象外にする(二重に引き継がない)。
        $this->artisan('special-leave:migrate-to-account', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, $this->migratedEvents());
        $this->assertSame(2.0, (float) SpecialLeaveGrant::query()->findOrFail($grantId)->remaining_days);
    }

    /** 移行前に新しい流れで付与が1件登録されていても、旧付与は引き継がれる(移行済みかどうかで冪等を判定する)。 */
    public function test_legacy_grant_is_migrated_even_when_a_new_grant_was_registered_before_migration(): void
    {
        $employee = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => '旧特別休暇', 'is_active' => true, 'requires_grant' => true]);
        $legacyGrantId = (string) Str::uuid();
        SpecialLeaveGrant::query()->create([
            'id' => $legacyGrantId, 'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
            'used_days' => 0, 'remaining_days' => 3, 'status' => 'active',
        ]);

        // 移行前(リリース後)に新しい流れで付与を登録する。
        $newGrantId = (string) Str::uuid();
        app(CommandBus::class)->dispatch(new RegisterSpecialLeaveGrant(
            userId: (string) $employee->id,
            grantId: $newGrantId,
            specialLeaveTypeId: $type->id,
            grantedOn: '2026-09-01',
            expiresOn: null,
            grantedDays: 2.0,
            grantReason: null,
        ));

        $this->artisan('special-leave:migrate-to-account', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, $this->migratedEvents());
        $this->assertSame(3.0, (float) SpecialLeaveGrant::query()->findOrFail($legacyGrantId)->remaining_days);
        $this->assertSame(2.0, (float) SpecialLeaveGrant::query()->findOrFail($newGrantId)->remaining_days);

        $this->artisan('special-leave:migrate-to-account', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, $this->migratedEvents());
    }

    public function test_after_migration_a_new_request_is_approved_and_cancelled_against_the_migrated_grant(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => '旧特別休暇', 'is_active' => true, 'requires_grant' => true]);
        $grantId = (string) Str::uuid();
        SpecialLeaveGrant::query()->create([
            'id' => $grantId, 'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
            'used_days' => 0, 'remaining_days' => 3, 'status' => 'active',
        ]);

        $this->artisan('special-leave:migrate-to-account', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, $this->migratedEvents());

        $this->workingDays($employee, ['2026-08-12']);
        $response = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-12',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated();
        $requestId = (string) $response->json('id');
        $workflowRequestId = WorkflowRequest::query()
            ->where('subject_type', 'special_leave_request')
            ->where('subject_id', $requestId)
            ->value('id');

        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")->assertOk();
        $this->assertSame(2.0, (float) SpecialLeaveGrant::query()->findOrFail($grantId)->remaining_days);

        $this->actingAs($employee)->postJson("/api/special-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
        $this->assertSame(3.0, (float) SpecialLeaveGrant::query()->findOrFail($grantId)->remaining_days);
        $this->assertNotNull($workflowRequestId);
    }
}
