<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Console\Attributes\AdminExecutable;
use App\Console\Commands\ReapplyPaidLeaveSchedulePolicyCommand;
use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Models\PaidLeaveFiscalYearSetting;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

/**
 * 運用コマンド`paid-leave:schedule:reapply-policy`(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点8)。
 * 作り直した社員の数と、付与予定日・日数が変わった行の数を出す。
 *
 * 前提: 今日=2026-10-21、全社共通ルール(初期ルール)、週5日の勤務形態。入社日2025-12-01の社員は、
 * 年度開始4/1なら今日から1年先までの付与予定が2027-04-01(名目30か月・12日)の1行、年度開始10/1なら2027-10-01の1行。
 */
class ReapplyPaidLeaveSchedulePolicyCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private WorkStyle $workStyle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21');
        $this->admin = User::factory()->create();
        $this->workStyle = app(CommandBus::class)->dispatch(new CreateWorkStyle(
            attributes: [
                'code' => 'FIVE', 'name' => 'FIVE', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
                'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT, 'prescribed_daily_minutes' => 480,
                'prescribed_weekly_minutes' => 2400, 'weekly_scheduled_days' => 5,
                'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY, 'is_shift_based' => false,
            ],
            createdByUserId: $this->admin->id,
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function userHiredOn(string $hireDate): User
    {
        $user = User::factory()->create();
        $bus = app(CommandBus::class);
        $bus->dispatch(new AssignUserWorkStyleForMonth(userId: $user->id, yearMonth: '2026-10', workStyleId: $this->workStyle->id, assignedByUserId: $this->admin->id));
        $bus->dispatch(new SetUserHireDate(userId: $user->id, hireDate: $hireDate, changedByUserId: $this->admin->id));

        return $user;
    }

    /**
     * @return array<int, array{string, int, float}>
     */
    private function activeEntries(User $user): array
    {
        return PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
            ->orderBy('scheduled_on')
            ->get()
            ->map(fn (PaidLeaveScheduleEntry $entry) => [$entry->scheduled_on->toDateString(), $entry->nominal_service_months, $entry->candidate_grant_days])
            ->all();
    }

    /**
     * イベントを経ずに、年度開始のビューだけを変える(カレンダーのイベントが無い状態で、既存の付与予定が古い日付のままの
     * 環境を作る。デプロイ後にこのコマンドで作り直す状況)。
     */
    private function setFiscalYearStartWithoutReapply(int $month, int $day): void
    {
        PaidLeaveFiscalYearSetting::query()->updateOrCreate(['id' => 1], ['fiscal_year_start_month' => $month, 'fiscal_year_start_day' => $day]);
    }

    public function test_it_reports_zero_changed_rows_when_nothing_changes(): void
    {
        $user = $this->userHiredOn('2025-12-01');
        $this->assertSame([['2027-04-01', 30, 12.0]], $this->activeEntries($user));

        $this->artisan('paid-leave:schedule:reapply-policy')
            ->expectsOutputToContain('作り直した社員: 1 名')
            ->expectsOutputToContain('付与予定日・日数が変わった行: 0 件')
            ->assertSuccessful();

        $this->assertSame([['2027-04-01', 30, 12.0]], $this->activeEntries($user));
    }

    public function test_it_recreates_entries_and_reports_the_changed_rows(): void
    {
        $changed = $this->userHiredOn('2025-12-01');
        $other = $this->userHiredOn('2025-12-01');
        $this->setFiscalYearStartWithoutReapply(10, 1);

        $this->artisan('paid-leave:schedule:reapply-policy')
            ->expectsOutputToContain('作り直した社員: 2 名')
            ->expectsOutputToContain('付与予定日・日数が変わった行: 2 件')
            ->assertSuccessful();

        $this->assertSame([['2027-10-01', 30, 12.0]], $this->activeEntries($changed));
        $this->assertSame([['2027-10-01', 30, 12.0]], $this->activeEntries($other));

        // 2回目は変わらない(冪等)。
        $this->artisan('paid-leave:schedule:reapply-policy')
            ->expectsOutputToContain('付与予定日・日数が変わった行: 0 件')
            ->assertSuccessful();
    }

    public function test_it_reports_rows_whose_days_changed_by_a_rule_edit(): void
    {
        $user = $this->userHiredOn('2025-12-01');
        $rule = PaidLeaveGrantRule::query()->whereNull('work_style_id')->firstOrFail();
        $rule->steps()->where('grant_category', 'normal')->where('continuous_service_months', 30)->update(['grant_days' => 13]);

        $this->artisan('paid-leave:schedule:reapply-policy')
            ->expectsOutputToContain('付与予定日・日数が変わった行: 1 件')
            ->assertSuccessful();

        $this->assertSame([['2027-04-01', 30, 13.0]], $this->activeEntries($user));
    }

    public function test_it_keeps_granted_entries_and_is_registered_as_an_admin_executable_command(): void
    {
        $user = $this->userHiredOn('2025-12-01');
        $granted = PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id, 'scheduled_on' => '2026-04-01', 'category' => 'normal', 'candidate_grant_days' => 10,
            'nominal_service_months' => 6, 'status' => PaidLeaveScheduleEntry::STATUS_GRANTED,
        ]);
        $this->setFiscalYearStartWithoutReapply(10, 1);

        $this->artisan('paid-leave:schedule:reapply-policy')->assertSuccessful();

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $granted->fresh()->status);
        // 付与済みの2026-04-01(名目6)を起点に、年度開始10/1の次回は2026-10-01(今日より前なので作らない)の次、2027-10-01(名目30)。
        $this->assertSame([['2027-10-01', 30, 12.0]], array_values(array_filter($this->activeEntries($user), fn ($e) => $e[0] > '2026-10-21')));

        $attribute = (new ReflectionClass(ReapplyPaidLeaveSchedulePolicyCommand::class))->getAttributes(AdminExecutable::class);
        $this->assertCount(1, $attribute);
    }
}
