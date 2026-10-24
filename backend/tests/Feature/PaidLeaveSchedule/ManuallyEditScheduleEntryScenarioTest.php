<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\Role;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 付与予定の個別修正(PATCH `/paid-leave/schedule-entries/{scheduleEntry}`)を起点にした
 * ユースケースのシナリオテスト(domain-test skill・ルートCLAUDE.md原則16)。
 * (a) 通常の再計算(入社日変更)では修正が保持される。
 * (b) 付与ルールの再適用(ReapplyPaidLeaveSchedulePolicyJob)では修正が作り直しで上書きされる。
 * docs/changesets/20261020-paid-leave-schedule-page-restore/spec.md 論点10。
 */
class ManuallyEditScheduleEntryScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createNormalWorkStyle(): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'NORMAL',
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
    }

    private function assignWorkStyle(User $user, WorkStyle $workStyle): void
    {
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id,
            'year_month' => '2020-01',
            'work_style_id' => $workStyle->id,
            'assigned_by_user_id' => $user->id,
        ]);
    }

    private function createAnniversaryRuleFor(WorkStyle $workStyle, int $days): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '通常勤務(周年)',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);

        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => $days]);

        return $rule;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $admin;
    }

    /**
     * 入社日2026-09-13・通常勤務・周年ルール(6ヶ月後から付与、10日)のユーザーを作り、
     * 付与予定(2027-03-13)を`paid-leave:roll-schedules`で生成する。
     *
     * @return array{0: User, 1: PaidLeaveScheduleEntry, 2: User, 3: PaidLeaveGrantRule}
     */
    private function setUpUserWithGeneratedEntry(int $grantDays): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));

        $workStyle = $this->createNormalWorkStyle();
        $rule = $this->createAnniversaryRuleFor($workStyle, $grantDays);

        $admin = $this->admin();
        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();
        $entry = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->firstOrFail();

        return [$user, $entry, $admin, $rule];
    }

    public function test_manual_edit_via_patch_survives_recalculation_triggered_by_hire_date_change(): void
    {
        [$user, $entry, $admin] = $this->setUpUserWithGeneratedEntry(grantDays: 10);

        $this->actingAs($admin)->patchJson("/api/paid-leave/schedule-entries/{$entry->id}", [
            'candidate_grant_days' => 7.5,
            'reason' => '個別事情による調整',
        ])->assertOk();

        // 入社日の変更で通常の再計算(RecalculateFutureSchedule、overrideManualEdits=false)が走る。
        // 付与ルールは10日のままなので、修正されていなければ候補は10日に戻ってしまう。
        app(CommandBus::class)->dispatch(new SetUserHireDate(
            userId: $user->id,
            hireDate: '2026-09-14',
            changedByUserId: $admin->id,
        ));

        $edited = $entry->fresh();
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $edited->status);
        $this->assertSame(7.5, $edited->candidate_grant_days);
        $this->assertSame('2027-03-13', $edited->scheduled_on->toDateString());
        $this->assertTrue($edited->is_manually_overridden);
        $this->assertSame('個別事情による調整', $edited->manual_override_reason);

        // 修正済みの付与予定日(2027-03-13)に重複エントリは作られない。
        $this->assertSame(
            1,
            PaidLeaveScheduleEntry::query()
                ->where('user_id', $user->id)
                ->whereDate('scheduled_on', '2027-03-13')
                ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
                ->count(),
        );
    }

    public function test_manual_edit_via_patch_is_recreated_by_policy_reapply(): void
    {
        [$user, $entry, $admin, $rule] = $this->setUpUserWithGeneratedEntry(grantDays: 10);

        $this->actingAs($admin)->patchJson("/api/paid-leave/schedule-entries/{$entry->id}", [
            'candidate_grant_days' => 7.5,
            'reason' => '個別事情による調整',
        ])->assertOk();
        $this->assertSame(7.5, $entry->fresh()->candidate_grant_days);

        // 付与ルールの日数を変更し、ポリシー変更再計算Job(overrideManualEdits=true)を実行する。
        $rule->steps()->update(['grant_days' => 12]);
        (new ReapplyPaidLeaveSchedulePolicyJob('付与ルールの編集'))->handle(
            app(CommandBus::class),
            app(ScheduleCandidateGenerator::class),
        );

        // 個別修正済みのエントリも新しい内容で作り直される(元のエントリはCancelled、新規は12日・未修正)。
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $entry->fresh()->status);

        $activeEntries = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
            ->get();
        $this->assertCount(1, $activeEntries);
        $this->assertSame(12.0, $activeEntries->first()->candidate_grant_days);
        $this->assertFalse($activeEntries->first()->is_manually_overridden);
    }
}
