<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Handlers\ApplyScheduledGrantsHandler;
use App\Models\LeaveAttendanceRateDay;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleAssessment;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * まとめて付与する2件(同じ社員・同じ付与予定日で名目が初回とその+12か月の2行)を組で扱うシナリオ
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点10)。
 * 再判定・上書き・個別修正・一括付与の各操作から、2行と有給口座の状態までを通して確かめる。
 */
class PaidLeaveBundledGrantScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PaidLeaveGrantRule::query()->update(['is_active' => false]);
        PaidLeaveGrantRule::query()->create([
            'name' => 'テスト全社ルール', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH, 'is_active' => true,
        ]);
    }

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $admin;
    }

    /**
     * 入社2026-01-10の社員に、2026-04-01の名目6か月(10日)と18か月(11日)の組を作る。
     *
     * @return array{0: string, 1: string} [6か月の行ID, 18か月の行ID]
     */
    private function createPair(User $user): array
    {
        $id6 = (string) Str::uuid();
        $id18 = (string) Str::uuid();
        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(userId: $user->id, candidates: [
            ['entryId' => $id6, 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
            ['entryId' => $id18, 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0, 'nominalServiceMonths' => 18],
        ]));

        return [$id6, $id18];
    }

    private function markEligible(User $user, string ...$entryIds): void
    {
        foreach ($entryIds as $entryId) {
            $this->bus()->dispatch(new RunAttendanceRateAssessment(
                userId: $user->id, scheduleEntryId: $entryId, assessmentId: (string) Str::uuid(),
                periodStart: '2026-01-10', periodEnd: '2026-03-31', denominatorDays: 55, attendanceDays: 50,
                excludedDays: 0, attendanceRate: 95.9, policyVersion: 'v2',
                automaticResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, deemedAttendanceDays: 68,
            ));
        }
    }

    private function statusOf(string $entryId): string
    {
        return PaidLeaveScheduleEntry::query()->findOrFail($entryId)->status;
    }

    public function test_bulk_grant_with_only_one_of_a_pair_is_rejected_and_changes_nothing(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['hire_date' => '2026-01-10']);
        [$id6, $id18] = $this->createPair($user);
        $this->markEligible($user, $id6, $id18);

        foreach ([$id6, $id18] as $only) {
            $response = $this->actingAs($admin)->postJson('/api/paid-leave/schedule-entries/bulk-grant', ['schedule_entry_ids' => [$only]]);

            $response->assertUnprocessable();
            $response->assertJsonValidationErrors(['schedule_entry_ids']);
            $this->assertStringContainsString('まとめて付与する2件は、同時に選んでください', $response->json('errors.schedule_entry_ids.0'));
        }

        $this->assertSame(0, PaidLeaveGrant::query()->where('user_id', $user->id)->count());
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $this->statusOf($id6));
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $this->statusOf($id18));
    }

    public function test_a_pair_mixed_with_other_users_entries_is_rejected_if_any_pair_is_incomplete(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['hire_date' => '2026-01-10']);
        $other = User::factory()->create(['hire_date' => '2025-10-01']);
        [$id6] = $this->createPair($user);
        $otherId = (string) Str::uuid();
        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(userId: $other->id, candidates: [
            ['entryId' => $otherId, 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
        ]));
        $this->markEligible($other, $otherId);

        $this->actingAs($admin)->postJson('/api/paid-leave/schedule-entries/bulk-grant', ['schedule_entry_ids' => [$otherId, $id6]])
            ->assertUnprocessable();

        // 他の社員の単独の行も付与されない(受付の前に全体を検証する)。
        $this->assertSame(0, PaidLeaveGrant::query()->count());
    }

    public function test_bulk_grant_with_both_rows_grants_two_entries_in_one_operation(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['hire_date' => '2026-01-10']);
        [$id6, $id18] = $this->createPair($user);
        $this->markEligible($user, $id6, $id18);

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/schedule-entries/bulk-grant', ['schedule_entry_ids' => [$id18, $id6]]);

        $response->assertOk();
        $this->assertSame(2, $response->json('success_count'));
        $this->assertSame(0, $response->json('failure_count'));

        $grants = PaidLeaveGrant::query()->where('user_id', $user->id)->orderBy('granted_days')->get();
        $this->assertCount(2, $grants);
        $this->assertSame(['2026-04-01', '2026-04-01'], $grants->map(fn ($g) => $g->granted_on->toDateString())->all());
        $this->assertSame([10.0, 11.0], $grants->map(fn ($g) => (float) $g->granted_days)->all());
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $this->statusOf($id6));
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $this->statusOf($id18));
        $this->assertSame(ApplyScheduledGrantsHandler::grantIdFor($id6), PaidLeaveScheduleEntry::query()->findOrFail($id6)->grant_id);
        $this->assertSame(ApplyScheduledGrantsHandler::grantIdFor($id18), PaidLeaveScheduleEntry::query()->findOrFail($id18)->grant_id);
    }

    public function test_if_one_grant_of_the_pair_fails_the_other_is_not_granted_either(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['hire_date' => '2026-01-10']);
        [$id6, $id18] = $this->createPair($user);
        $this->markEligible($user, $id6, $id18);

        // 18か月側の付与IDが既に有給口座に記録されている(2件目の付与だけが失敗する)状態にする。
        $this->bus()->dispatch(new GrantPaidLeave(
            userId: $user->id, grantedOn: '2026-04-01', expiresOn: '2028-04-01', grantedDays: 11.0,
            grantReason: '先に記録', source: 'scheduled_batch', grantId: ApplyScheduledGrantsHandler::grantIdFor($id18),
        ));

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/schedule-entries/bulk-grant', ['schedule_entry_ids' => [$id6, $id18]]);

        $response->assertOk();
        $this->assertSame(0, $response->json('success_count'));
        $this->assertSame(2, $response->json('failure_count'));

        // 有給口座には、先に記録した1件だけ。6か月側(1件目)の付与も記録されない。
        $this->assertSame(
            [ApplyScheduledGrantsHandler::grantIdFor($id18)],
            PaidLeaveGrant::query()->where('user_id', $user->id)->pluck('id')->all(),
        );
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $this->statusOf($id6));
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $this->statusOf($id18));
    }

    public function test_the_handler_refuses_to_grant_half_of_a_pair(): void
    {
        $user = User::factory()->create(['hire_date' => '2026-01-10']);
        [$id6, $id18] = $this->createPair($user);
        $this->markEligible($user, $id6, $id18);

        $outcome = $this->bus()->dispatch(new ApplyScheduledGrants(
            userId: $user->id, scheduleEntryIds: [$id6], operatorUserId: $user->id,
        ));

        $this->assertSame([], $outcome['granted']);
        $this->assertSame([$id6], array_column($outcome['failed'], 'scheduleEntryId'));
        $this->assertSame(0, PaidLeaveGrant::query()->count());
    }

    public function test_reassessing_one_row_assesses_both_rows_and_shows_deemed_days(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['hire_date' => '2026-01-10', 'usage_start_date' => null]);
        [$id6, $id18] = $this->createPair($user);
        // 所定55日・出勤10日(みなし68日を加えても63.4%)。
        $date = Carbon::parse('2026-01-10');
        for ($i = 0; $i < 55; $i++, $date->addDay()) {
            LeaveAttendanceRateDay::query()->create([
                'user_id' => $user->id, 'work_date' => $date->toDateString(), 'is_working_day' => true,
                'attended' => $i < 10, 'full_leave_kinds' => [], 'partial_leave_kinds' => [],
            ]);
        }

        $this->actingAs($admin)->postJson("/api/paid-leave/schedule-entries/{$id18}/reassess")->assertOk();

        // 初回(6か月)は対象外、まとめた2件目(18か月)は判定を共有せず付与対象。
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_NOT_ELIGIBLE, $this->statusOf($id6));
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $this->statusOf($id18));

        $detail = $this->actingAs($admin)->getJson("/api/paid-leave/schedule-entries/{$id6}")->assertOk();
        $detail->assertJsonPath('data.assessments.0.deemed_attendance_days', 68);
        $detail->assertJsonPath('data.assessments.0.denominator_days', 55);
        $detail->assertJsonPath('data.assessments.0.attendance_days', 10);
        $detail->assertJsonPath('data.assessments.0.policy_version', 'v2');
        $detail->assertJsonPath('data.assessments.0.period_start', '2026-01-10');
        $detail->assertJsonPath('data.assessments.0.period_end', '2026-03-31');

        $detail18 = $this->actingAs($admin)->getJson("/api/paid-leave/schedule-entries/{$id18}")->assertOk();
        $detail18->assertJsonPath('data.assessments.0.deemed_attendance_days', 0);
        $detail18->assertJsonPath('data.assessments.0.period_start', '2026-04-01');
        $detail18->assertJsonPath('data.assessments.0.period_end', '2027-07-09');
    }

    public function test_override_through_the_api_applies_to_both_rows(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['hire_date' => '2026-01-10']);
        [$id6, $id18] = $this->createPair($user);
        $this->markEligible($user, $id6, $id18);

        $this->actingAs($admin)->postJson("/api/paid-leave/schedule-entries/{$id6}/override", [
            'final_result' => PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
            'reason' => '長期欠勤のため',
        ])->assertOk();

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_NOT_ELIGIBLE, $this->statusOf($id6));
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_NOT_ELIGIBLE, $this->statusOf($id18));
        $this->assertSame(2, PaidLeaveScheduleAssessment::query()->where('final_result', 'NotEligible')->where('override_reason', '長期欠勤のため')->count());
    }

    public function test_manual_edit_through_the_api_applies_to_both_rows(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['hire_date' => '2026-01-10']);
        [$id6, $id18] = $this->createPair($user);

        $this->actingAs($admin)->patchJson("/api/paid-leave/schedule-entries/{$id18}", [
            'candidate_grant_days' => 12.5,
            'reason' => '個別調整',
        ])->assertOk();

        foreach ([$id6, $id18] as $entryId) {
            $entry = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
            $this->assertSame(12.5, $entry->candidate_grant_days);
            $this->assertTrue($entry->is_manually_overridden);
        }
    }
}
