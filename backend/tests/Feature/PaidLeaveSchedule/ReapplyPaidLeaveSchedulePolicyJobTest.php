<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * docs/changesets/20260916-paid-leave-policy-change-reapply/spec.md:
 * 法定付与ポリシー・付与ルール変更時に、未確定(Granted/Cancelled以外)のScheduleエントリを
 * 個別修正の有無に関わらず新しい内容へ再作成することを検証する。
 */
class ReapplyPaidLeaveSchedulePolicyJobTest extends TestCase
{
    use RefreshDatabase;

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

    private function assignWorkStyle(User $user, WorkStyle $workStyle, string $yearMonth = '2020-01'): void
    {
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id,
            'year_month' => $yearMonth,
            'work_style_id' => $workStyle->id,
            'assigned_by_user_id' => $user->id,
        ]);
    }

    private function createAnniversaryRuleFor(WorkStyle $workStyle, int $days = 10): PaidLeaveGrantRule
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

    public function test_it_recreates_unconfirmed_entries_including_manually_edited_ones(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));

        $workStyle = $this->createNormalWorkStyle();
        $rule = $this->createAnniversaryRuleFor($workStyle, days: 10);

        $admin = User::factory()->create();
        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle);

        // 一度Scheduleを生成し、内容を管理者が個別修正する。
        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();
        $entry = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->firstOrFail();

        PaidLeaveScheduleAggregate::retrieve($user->id)
            ->manuallyEditScheduleEntry($entry->id, ['candidateGrantDays' => 9.0], '個別事情', $admin->id)
            ->persist();

        $this->assertSame(9.0, (float) $entry->fresh()->candidate_grant_days);
        $this->assertTrue((bool) $entry->fresh()->is_manually_overridden);

        // ルールの日数を変更し、ポリシー変更再計算Jobを実行する。
        $rule->steps()->update(['grant_days' => 12]);
        (new ReapplyPaidLeaveSchedulePolicyJob('付与ルールの編集'))->handle(
            app(CommandBus::class),
            app(ScheduleCandidateGenerator::class),
        );

        // 元のエントリはSupersede(取消相当)され、新しい内容のエントリが新規作成される。
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $entry->fresh()->status);

        $activeEntries = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
            ->get();
        $this->assertCount(1, $activeEntries);
        $this->assertSame(12.0, (float) $activeEntries->first()->candidate_grant_days);
        $this->assertFalse((bool) $activeEntries->first()->is_manually_overridden);
    }

    public function test_it_does_not_touch_granted_entries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));

        $workStyle = $this->createNormalWorkStyle();
        $rule = $this->createAnniversaryRuleFor($workStyle, days: 10);

        $admin = User::factory()->create();
        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();
        $entry = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->firstOrFail();

        PaidLeaveScheduleAggregate::retrieve($user->id)
            ->runAttendanceRateAssessment($entry->id, 'assessment-1', '2026-03-01', '2026-03-13', 10, 10, 0, 1.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE)
            ->grantEntry($entry->id, 'grant-1', $admin->id)
            ->persist();

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $entry->fresh()->status);

        $rule->steps()->update(['grant_days' => 12]);
        (new ReapplyPaidLeaveSchedulePolicyJob('付与ルールの編集'))->handle(
            app(CommandBus::class),
            app(ScheduleCandidateGenerator::class),
        );

        // Granted済みエントリ自体は内容・ステータスとも変更されない(確定済みは常に不変)。
        // 同じscheduledOnの候補は、確定済みエントリとは別扱いのため新規エントリとして
        // 追加生成されうる(既存の`recalculateFutureSchedule`の一般的な挙動。
        // `PaidLeaveScheduleAggregateTest::test_past_confirmed_granted_entry_is_immutable_under_recalculation`
        // 参照)。
        $entry->refresh();
        $this->assertSame(10.0, (float) $entry->candidate_grant_days);
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $entry->status);
    }
}
