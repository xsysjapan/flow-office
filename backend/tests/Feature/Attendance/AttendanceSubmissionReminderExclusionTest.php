<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Aggregates\AttendanceSubmissionReminderExclusionAggregate;
use App\Domain\Attendance\Commands\ExcludeAttendanceSubmissionReminder;
use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceSubmissionReminderExclusion;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 勤怠未提出督促(WarnUnsubmittedAttendanceHandler)の個別除外
 * (attendance.submission_reminder_excluded)。誤ってその月を提出対象にしてしまった等の
 * 例外的対応として、usage_start_date/hire_dateによる除外条件とは別に、社員×年月を
 * 個別に除外できることを確認する。
 */
class AttendanceSubmissionReminderExclusionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['employment_status' => 'active']);
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_command_handler_records_an_exclusion(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['employment_status' => 'active']);

        $exclusion = app(CommandBus::class)->dispatch(new ExcludeAttendanceSubmissionReminder(
            userId: $target->id,
            yearMonth: '2026-06',
            reason: '利用開始日より前の月を誤って対象にしていたため',
            excludedByUserId: $admin->id,
        ));

        $this->assertInstanceOf(AttendanceSubmissionReminderExclusion::class, $exclusion);
        $this->assertDatabaseHas('attendance_submission_reminder_exclusions', [
            'id' => $exclusion->id,
            'user_id' => $target->id,
            'year_month' => '2026-06',
            'reason' => '利用開始日より前の月を誤って対象にしていたため',
            'excluded_by_user_id' => $admin->id,
        ]);
    }

    public function test_command_handler_reuses_the_same_row_for_the_same_user_and_year_month(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $first = app(CommandBus::class)->dispatch(new ExcludeAttendanceSubmissionReminder(
            userId: $target->id, yearMonth: '2026-06', reason: '当初の理由', excludedByUserId: $admin->id,
        ));

        $second = app(CommandBus::class)->dispatch(new ExcludeAttendanceSubmissionReminder(
            userId: $target->id, yearMonth: '2026-06', reason: '更新後の理由', excludedByUserId: $admin->id,
        ));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AttendanceSubmissionReminderExclusion::query()->count());
        $this->assertSame('更新後の理由', $second->fresh()->reason);
    }

    public function test_command_handler_rejects_a_malformed_year_month(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->expectException(DomainRuleException::class);

        app(CommandBus::class)->dispatch(new ExcludeAttendanceSubmissionReminder(
            userId: $target->id, yearMonth: '2026/06', reason: '理由', excludedByUserId: $admin->id,
        ));
    }

    public function test_command_handler_rejects_a_blank_reason(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->expectException(DomainRuleException::class);

        app(CommandBus::class)->dispatch(new ExcludeAttendanceSubmissionReminder(
            userId: $target->id, yearMonth: '2026-06', reason: '   ', excludedByUserId: $admin->id,
        ));
    }

    public function test_projector_rebuilds_the_row_from_the_aggregate_event(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $id = (string) Str::uuid();

        AttendanceSubmissionReminderExclusionAggregate::retrieve($id)
            ->exclude(userId: $target->id, yearMonth: '2026-06', reason: '再生成テスト', excludedByUserId: $admin->id)
            ->persist();

        $this->assertDatabaseHas('attendance_submission_reminder_exclusions', [
            'id' => $id,
            'user_id' => $target->id,
            'year_month' => '2026-06',
            'reason' => '再生成テスト',
        ]);
    }

    public function test_admin_can_exclude_via_api(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/attendance-submission-reminder-exclusions', [
            'user_id' => $target->id,
            'year_month' => '2026-06',
            'reason' => '誤って対象月にしていたため',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('user_id', $target->id);
        $response->assertJsonPath('year_month', '2026-06');
        $response->assertJsonPath('reason', '誤って対象月にしていたため');
    }

    public function test_non_admin_cannot_exclude_via_api(): void
    {
        $employee = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance-submission-reminder-exclusions', [
            'user_id' => $target->id,
            'year_month' => '2026-06',
            'reason' => '理由',
        ])->assertForbidden();
    }

    public function test_admin_can_list_exclusions_filtered_by_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $other = User::factory()->create();

        AttendanceSubmissionReminderExclusion::query()->create([
            'user_id' => $target->id, 'year_month' => '2026-05', 'reason' => '対象社員分', 'excluded_by_user_id' => $admin->id,
        ]);
        AttendanceSubmissionReminderExclusion::query()->create([
            'user_id' => $other->id, 'year_month' => '2026-05', 'reason' => '別社員分', 'excluded_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson("/api/attendance-submission-reminder-exclusions?user_id={$target->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $response->assertJsonPath('0.user_id', $target->id);
    }

    public function test_warn_unsubmitted_attendance_skips_users_with_an_exclusion_for_the_target_month(): void
    {
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 5]);
        // employment_status=resignedにして、この管理者自身が未提出フォローの集計対象に
        // 混ざらないようにする(excluded_by_user_idの参照先として使うだけ)。
        $admin = User::factory()->create(['employment_status' => 'resigned']);

        $excludedUser = User::factory()->create(['employment_status' => 'active', 'usage_start_date' => '2026-01-01']);
        AttendanceSubmissionReminderExclusion::query()->create([
            'user_id' => $excludedUser->id, 'year_month' => '2026-06', 'reason' => '誤って対象月にしていたため', 'excluded_by_user_id' => $admin->id,
        ]);

        // 除外されていない未提出社員は引き続き対象。
        User::factory()->create(['employment_status' => 'active', 'usage_start_date' => '2026-01-01']);

        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance(asOf: '2026-07-06'));

        $this->assertSame(1, $count);
    }

    public function test_warn_unsubmitted_attendance_exclusion_is_scoped_to_the_specific_year_month(): void
    {
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 5]);
        $admin = User::factory()->create(['employment_status' => 'resigned']);

        $user = User::factory()->create(['employment_status' => 'active', 'usage_start_date' => '2026-01-01']);
        // 対象月(2026-06)ではなく別の月の除外なので、今回のフォローは対象のまま。
        AttendanceSubmissionReminderExclusion::query()->create([
            'user_id' => $user->id, 'year_month' => '2026-05', 'reason' => '別の月の除外', 'excluded_by_user_id' => $admin->id,
        ]);

        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance(asOf: '2026-07-06'));

        $this->assertSame(1, $count);
    }
}
