<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\Attendance\Commands\RemoveUserWorkStyleMonthlyAssignment;
use App\Domain\Attendance\Commands\UpdateWorkStyle;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Projectors\PaidLeaveScheduleProjector;
use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use App\Domain\UserManagement\Commands\SetPaidLeaveAutoGrantEnabled;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Commands\SetUserUsageStartDate;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveGrantRuleStep;
use App\Models\PaidLeaveScheduleAssessment;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 付与予定の再計算Reactor 2本(`RecalculateScheduleOnUserConditionChangedReactor`・
 * `RecalculateScheduleOnWorkStyleChangedReactor`)と、日次の`paid-leave:roll-schedules`を、Commandの発行から
 * 付与予定の状態までを通して確かめるシナリオテスト(docs/changesets/20261021-paid-leave-schedule-reactor-tests/spec.md 論点1、
 * docs/changesets/20261021-paid-leave-schedule-recalc-on-assignment-change/spec.md)。
 *
 * 前提: 今日=2026-10-21、入社日2024-01-01。勤務形態ごとの付与ルール(入社日基準)で、付与予定は
 * 2027-07-01(継続42か月)の1行になる(週5日=14日・通常、週3日=8日・比例)。
 */
class ScheduleRecalculationReactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21');
        $this->admin = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createWorkStyle(string $code, array $overrides = []): WorkStyle
    {
        return $this->bus()->dispatch(new CreateWorkStyle(
            attributes: array_merge([
                'code' => $code,
                'name' => $code,
                'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
                'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
                'prescribed_daily_minutes' => 480,
                'prescribed_weekly_minutes' => 2400,
                'weekly_scheduled_days' => 5,
                'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
                'is_shift_based' => false,
            ], $overrides),
            createdByUserId: $this->admin->id,
        ));
    }

    private function fiveDayWorkStyle(): WorkStyle
    {
        $workStyle = $this->createWorkStyle('FIVE');
        $this->createRule($workStyle, [6 => 10, 18 => 11, 30 => 12, 42 => 14]);

        return $workStyle;
    }

    private function threeDayWorkStyle(): WorkStyle
    {
        $workStyle = $this->createWorkStyle('THREE', [
            'weekly_scheduled_days' => 3,
            'annual_scheduled_days' => null,
            'prescribed_weekly_minutes' => 1440,
            'is_shift_based' => false,
        ]);
        // 区分が変わっても日数が同じになるよう、比例(週3日)の表と通常の表に同じ日数を入れる。
        $this->createRule($workStyle, [6 => 5, 18 => 6, 30 => 6, 42 => 8], 'proportional_3');
        $this->createRule($workStyle, [6 => 5, 18 => 6, 30 => 6, 42 => 8], 'normal', createRule: false);

        return $workStyle;
    }

    /**
     * @param  array<int, int>  $stepsByMonths  継続勤務月数 => 付与日数
     * @param  string  $grantCategory  付与日数の表の区分(比例の社員は`proportional_N`の表を引く)
     */
    private function createRule(WorkStyle $workStyle, array $stepsByMonths, string $grantCategory = 'normal', bool $createRule = true): void
    {
        $rule = $createRule
            ? PaidLeaveGrantRule::query()->create([
                'name' => $workStyle->code,
                'work_style_id' => $workStyle->id,
                'min_attendance_rate' => 80,
                'first_grant_after_months' => 6,
                'grant_cycle_months' => 12,
                'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
                'is_active' => true,
            ])
            : PaidLeaveGrantRule::query()->where('work_style_id', $workStyle->id)->firstOrFail();

        foreach ($stepsByMonths as $months => $days) {
            $rule->steps()->create(['grant_category' => $grantCategory, 'continuous_service_months' => $months, 'grant_days' => $days]);
        }
    }

    /**
     * 勤務形態を割り当ててから入社日(2024-01-01)を設定する。割当を先にすることで、勤務形態の
     * 無い状態(全社共通ルール)の予定が作られない。
     */
    private function userOn(WorkStyle $workStyle, string $assignedMonth = '2026-10'): User
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new AssignUserWorkStyleForMonth(
            userId: $user->id,
            yearMonth: $assignedMonth,
            workStyleId: $workStyle->id,
            assignedByUserId: $this->admin->id,
        ));
        $this->setHireDate($user, '2024-01-01');

        return $user;
    }

    private function setHireDate(User $user, string $hireDate): void
    {
        $this->bus()->dispatch(new SetUserHireDate(
            userId: $user->id,
            hireDate: $hireDate,
            changedByUserId: $this->admin->id,
        ));
    }

    private function scheduleEventCount(): int
    {
        return DB::table('stored_events')->where('event_class', 'like', 'paid_leave_schedule.%')->count();
    }

    /**
     * @return array<int, PaidLeaveScheduleEntry>
     */
    private function activeEntries(User $user): array
    {
        return PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
            ->orderBy('scheduled_on')
            ->get()
            ->all();
    }

    private function onlyActiveEntry(User $user): PaidLeaveScheduleEntry
    {
        $entries = $this->activeEntries($user);
        $this->assertCount(1, $entries);

        return $entries[0];
    }

    private function entryOn(User $user, string $scheduledOn): ?PaidLeaveScheduleEntry
    {
        return PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('scheduled_on', $scheduledOn)
            ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
            ->first();
    }

    private function assertEntry(PaidLeaveScheduleEntry $entry, string $scheduledOn, string $category, float $days): void
    {
        $this->assertSame($scheduledOn, $entry->scheduled_on->toDateString());
        $this->assertSame($category, $entry->category);
        $this->assertSame($days, $entry->candidate_grant_days);
    }

    private function grantEntry(User $user, PaidLeaveScheduleEntry $entry): void
    {
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: $entry->id,
            assessmentId: (string) Str::uuid(),
            periodStart: '2026-07-01',
            periodEnd: '2027-07-01',
            denominatorDays: 20,
            attendanceDays: 18,
            excludedDays: 0,
            attendanceRate: 90.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
        ));
        $this->bus()->dispatch(new ApplyScheduledGrants(
            userId: $user->id,
            scheduleEntryIds: [$entry->id],
            operatorUserId: $this->admin->id,
        ));

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $entry->fresh()->status);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entrySnapshot(): array
    {
        return PaidLeaveScheduleEntry::query()
            ->orderBy('id')
            ->get(['id', 'user_id', 'scheduled_on', 'category', 'candidate_grant_days', 'status', 'cancelled_reason', 'grant_id'])
            ->map(fn (PaidLeaveScheduleEntry $entry) => $entry->toArray())
            ->all();
    }

    /**
     * 付与予定のProjectionを空にしてイベントから作り直し、同じ結果になることを確かめる(設計原則2)。
     */
    private function assertProjectionRebuildsToSameState(): void
    {
        $before = $this->entrySnapshot();
        $this->assertNotEmpty($before);

        PaidLeaveScheduleAssessment::query()->delete();
        PaidLeaveScheduleEntry::query()->delete();
        $this->assertSame(0, PaidLeaveScheduleEntry::query()->count());

        Artisan::call('event-sourcing:replay', ['projector' => [PaidLeaveScheduleProjector::class], '--force' => true]);

        $this->assertSame($before, $this->entrySnapshot());
    }

    // ---- 入社日・利用開始日・自動付与(1本目のReactor) ----

    public function test_setup_creates_single_schedule_from_work_style_rule(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());

        // 全社共通ルールではなく勤務形態のルール(14日)が選ばれる。
        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_NORMAL, 14.0);
    }

    public function test_changing_hire_date_cancels_old_entry_and_creates_new_one(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());
        $oldEntry = $this->onlyActiveEntry($user);

        // 2025-02-01入社: 継続30か月は2027-08-01(12日)になり、2027-07-01の行は不要になる。
        $this->setHireDate($user, '2025-02-01');

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $oldEntry->fresh()->status);
        $this->assertEntry($this->onlyActiveEntry($user), '2027-08-01', GrantCategoryClassifier::CATEGORY_NORMAL, 12.0);

        $this->assertProjectionRebuildsToSameState();
    }

    public function test_setting_the_same_hire_date_again_records_no_schedule_events(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());
        $entry = $this->onlyActiveEntry($user);
        $baseline = $this->scheduleEventCount();

        $this->setHireDate($user, '2024-01-01');

        $this->assertSame($baseline, $this->scheduleEventCount());
        $this->assertSame($entry->id, $this->onlyActiveEntry($user)->id);
    }

    public function test_setting_usage_start_date_after_scheduled_date_cancels_entry(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());
        $entry = $this->onlyActiveEntry($user);

        $this->bus()->dispatch(new SetUserUsageStartDate(
            userId: $user->id,
            usageStartDate: '2027-08-01',
            changedByUserId: $this->admin->id,
        ));

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $entry->fresh()->status);
        $this->assertSame([], $this->activeEntries($user));
    }

    public function test_disabling_auto_grant_cancels_entry_and_enabling_recreates_it(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());
        $entry = $this->onlyActiveEntry($user);

        $this->bus()->dispatch(new SetPaidLeaveAutoGrantEnabled(
            userId: $user->id,
            enabled: false,
            changedByUserId: $this->admin->id,
        ));

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $entry->fresh()->status);
        $this->assertSame([], $this->activeEntries($user));

        $this->bus()->dispatch(new SetPaidLeaveAutoGrantEnabled(
            userId: $user->id,
            enabled: true,
            changedByUserId: $this->admin->id,
        ));

        $recreated = $this->onlyActiveEntry($user);
        $this->assertNotSame($entry->id, $recreated->id);
        $this->assertEntry($recreated, '2027-07-01', GrantCategoryClassifier::CATEGORY_NORMAL, 14.0);
    }

    public function test_granted_entry_on_today_is_kept_and_no_duplicate_is_created_for_same_date(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());
        $entry = $this->onlyActiveEntry($user);

        // 付与予定日(2027-07-01)当日に付与する。
        Carbon::setTestNow('2027-07-01');
        $this->grantEntry($user, $entry);

        // 付与後に再計算を起こす(利用開始日を入社日に設定)。
        $this->bus()->dispatch(new SetUserUsageStartDate(
            userId: $user->id,
            usageStartDate: '2024-01-01',
            changedByUserId: $this->admin->id,
        ));

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $entry->fresh()->status);
        $this->assertSame(
            1,
            PaidLeaveScheduleEntry::query()
                ->where('user_id', $user->id)
                ->whereDate('scheduled_on', '2027-07-01')
                ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
                ->count(),
        );
        // 次の付与予定(継続54か月)は作られる。
        $this->assertNotNull($this->entryOn($user, '2028-07-01'));
    }

    public function test_granted_entry_in_the_past_is_not_changed_by_recalculation(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());
        $entry = $this->onlyActiveEntry($user);

        Carbon::setTestNow('2027-07-01');
        $this->grantEntry($user, $entry);

        Carbon::setTestNow('2027-09-01');
        $this->setHireDate($user, '2025-02-01');

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $entry->fresh()->status);
        $this->assertSame(14.0, $entry->fresh()->candidate_grant_days);
        $this->assertSame(
            1,
            PaidLeaveScheduleEntry::query()
                ->where('user_id', $user->id)
                ->whereDate('scheduled_on', '2027-07-01')
                ->count(),
        );
    }

    public function test_overdue_ungranted_entry_is_not_cancelled_by_hire_date_change(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());
        $overdue = $this->onlyActiveEntry($user);

        // 付与予定日(2027-07-01)を過ぎても未付与のまま。
        Carbon::setTestNow('2027-08-01');
        $this->setHireDate($user, '2025-01-01');

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $overdue->fresh()->status);
        $this->assertNull($overdue->fresh()->cancelled_reason);
        // 新しい条件の予定日(継続42か月=2028-07-01)は作られる。
        $this->assertNotNull($this->entryOn($user, '2028-07-01'));
    }

    // ---- 勤務形態(2本目のReactor) ----

    public function test_assigning_five_day_work_style_for_current_month_changes_category_and_days(): void
    {
        $five = $this->fiveDayWorkStyle();
        $three = $this->threeDayWorkStyle();
        $user = $this->userOn($three);

        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_PROPORTIONAL, 8.0);

        $this->bus()->dispatch(new AssignUserWorkStyleForMonth(
            userId: $user->id,
            yearMonth: '2026-10',
            workStyleId: $five->id,
            assignedByUserId: $this->admin->id,
        ));

        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_NORMAL, 14.0);

        $this->assertProjectionRebuildsToSameState();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function classificationAttributeChanges(): array
    {
        return [
            'weekly_scheduled_days 3→5' => [['weekly_scheduled_days' => 5], GrantCategoryClassifier::CATEGORY_NORMAL],
            'annual_scheduled_days null→217' => [['annual_scheduled_days' => 217], GrantCategoryClassifier::CATEGORY_NORMAL],
            'prescribed_weekly_minutes 1440→1800' => [['prescribed_weekly_minutes' => 1800], GrantCategoryClassifier::CATEGORY_NORMAL],
            'is_shift_based false→true' => [['is_shift_based' => true], GrantCategoryClassifier::CATEGORY_SHIFT],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('classificationAttributeChanges')]
    public function test_updating_classification_attribute_of_work_style_recalculates_assigned_user(array $attributes, string $expectedCategory): void
    {
        $three = $this->threeDayWorkStyle();
        $user = $this->userOn($three);
        $oldEntry = $this->onlyActiveEntry($user);
        $this->assertSame(GrantCategoryClassifier::CATEGORY_PROPORTIONAL, $oldEntry->category);

        $this->bus()->dispatch(new UpdateWorkStyle(
            workStyleId: $three->id,
            attributes: $attributes,
            updatedByUserId: $this->admin->id,
        ));

        // 取消+新規で区分だけが変わる(日数は同じ勤務形態のルールなので8日のまま)。
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $oldEntry->fresh()->status);
        $newEntry = $this->onlyActiveEntry($user);
        $this->assertNotSame($oldEntry->id, $newEntry->id);
        $this->assertSame($expectedCategory, $newEntry->category);
        $this->assertSame('2027-07-01', $newEntry->scheduled_on->toDateString());
        if ($expectedCategory === GrantCategoryClassifier::CATEGORY_NORMAL) {
            $this->assertSame(8.0, $newEntry->candidate_grant_days);
        }
    }

    public function test_updating_only_the_name_of_work_style_records_no_schedule_events(): void
    {
        $three = $this->threeDayWorkStyle();
        $this->userOn($three);
        $baseline = $this->scheduleEventCount();

        $this->bus()->dispatch(new UpdateWorkStyle(
            workStyleId: $three->id,
            attributes: ['name' => '名前だけ変更'],
            updatedByUserId: $this->admin->id,
        ));

        $this->assertSame($baseline, $this->scheduleEventCount());
    }

    public function test_updating_work_style_without_assigned_users_does_nothing(): void
    {
        $three = $this->threeDayWorkStyle();
        $this->userOn($three);
        $unassigned = $this->createWorkStyle('UNASSIGNED', ['weekly_scheduled_days' => 3, 'prescribed_weekly_minutes' => 1440]);
        $baseline = $this->scheduleEventCount();

        $this->bus()->dispatch(new UpdateWorkStyle(
            workStyleId: $unassigned->id,
            attributes: ['weekly_scheduled_days' => 5],
            updatedByUserId: $this->admin->id,
        ));

        $this->assertSame($baseline, $this->scheduleEventCount());
    }

    public function test_updating_work_style_recalculates_user_assigned_only_in_a_past_month(): void
    {
        $three = $this->threeDayWorkStyle();
        // 2026-01に1回だけ割り当て、今(2026-10)もその割当が有効な社員。
        $user = $this->userOn($three, '2026-01');
        $oldEntry = $this->onlyActiveEntry($user);
        $this->assertSame(GrantCategoryClassifier::CATEGORY_PROPORTIONAL, $oldEntry->category);

        $this->bus()->dispatch(new UpdateWorkStyle(
            workStyleId: $three->id,
            attributes: ['weekly_scheduled_days' => 5],
            updatedByUserId: $this->admin->id,
        ));

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $oldEntry->fresh()->status);
        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_NORMAL, 8.0);

        $this->assertProjectionRebuildsToSameState();
    }

    // ---- 勤務形態の割当の削除・月替わり(roll-schedules) ----

    private function assignWorkStyleForMonth(User $user, WorkStyle $workStyle, string $yearMonth): UserWorkStyleMonthlyAssignment
    {
        return $this->bus()->dispatch(new AssignUserWorkStyleForMonth(
            userId: $user->id,
            yearMonth: $yearMonth,
            workStyleId: $workStyle->id,
            assignedByUserId: $this->admin->id,
        ));
    }

    private function removeAssignment(UserWorkStyleMonthlyAssignment $assignment): void
    {
        $this->bus()->dispatch(new RemoveUserWorkStyleMonthlyAssignment(
            assignmentId: $assignment->id,
            removedByUserId: $this->admin->id,
        ));
    }

    public function test_removing_current_month_assignment_recalculates_to_previous_month_work_style(): void
    {
        $five = $this->fiveDayWorkStyle();
        $three = $this->threeDayWorkStyle();
        $user = $this->userOn($three, '2026-09');
        $current = $this->assignWorkStyleForMonth($user, $five, '2026-10');
        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_NORMAL, 14.0);

        // 当月(2026-10)の割当を削除すると、前月(2026-09)の週3日が有効になる。
        $this->removeAssignment($current);

        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_PROPORTIONAL, 8.0);
        $this->assertProjectionRebuildsToSameState();
    }

    public function test_removing_future_month_assignment_records_no_schedule_events(): void
    {
        $five = $this->fiveDayWorkStyle();
        $three = $this->threeDayWorkStyle();
        $user = $this->userOn($three, '2026-09');
        $future = $this->assignWorkStyleForMonth($user, $five, '2026-12');
        $baseline = $this->scheduleEventCount();

        // 当月より後の割当は今の計算の結果を変えないため、再計算しても予定は変わらず、イベントも増えない。
        $this->removeAssignment($future);

        $this->assertSame($baseline, $this->scheduleEventCount());
        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_PROPORTIONAL, 8.0);
    }

    public function test_roll_applies_next_month_assignment_when_it_takes_effect(): void
    {
        $five = $this->fiveDayWorkStyle();
        $three = $this->threeDayWorkStyle();
        $user = $this->userOn($three, '2026-10');
        // 先の月(2026-11)の週5日は、10月の時点ではまだ有効でない。
        $this->assignWorkStyleForMonth($user, $five, '2026-11');
        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_PROPORTIONAL, 8.0);

        Carbon::setTestNow('2026-11-01');
        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertEntry($this->onlyActiveEntry($user), '2027-07-01', GrantCategoryClassifier::CATEGORY_NORMAL, 14.0);
        $this->assertProjectionRebuildsToSameState();
    }

    public function test_roll_without_any_change_records_no_schedule_events(): void
    {
        $user = $this->userOn($this->fiveDayWorkStyle());
        $entry = $this->onlyActiveEntry($user);
        $baseline = $this->scheduleEventCount();

        Carbon::setTestNow('2026-10-22');
        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertSame($baseline, $this->scheduleEventCount());
        $this->assertSame($entry->id, $this->onlyActiveEntry($user)->id);
    }

    public function test_roll_keeps_manually_edited_entry(): void
    {
        $three = $this->threeDayWorkStyle();
        $five = $this->fiveDayWorkStyle();
        $user = $this->userOn($three, '2026-10');
        $entry = $this->onlyActiveEntry($user);
        $this->bus()->dispatch(new ManuallyEditScheduleEntry(
            userId: $user->id,
            scheduleEntryId: $entry->id,
            changes: ['candidateGrantDays' => 9.0],
            reason: '個別事情による調整',
            operatorUserId: $this->admin->id,
        ));
        $this->assignWorkStyleForMonth($user, $five, '2026-11');

        // 再計算すると通常・14日になるはずの行だが、個別修正の行は残る。
        Carbon::setTestNow('2026-11-01');
        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $kept = $this->onlyActiveEntry($user);
        $this->assertSame($entry->id, $kept->id);
        $this->assertEntry($kept, '2027-07-01', GrantCategoryClassifier::CATEGORY_PROPORTIONAL, 9.0);
    }

    public function test_roll_does_not_change_entry_scheduled_today(): void
    {
        $three = $this->threeDayWorkStyle();
        // 入社日2024-04-21なら、今日(2026-10-21)が30か月目の付与予定日になる。
        $user = $this->userOn($three, '2026-10');
        $this->setHireDate($user, '2024-04-21');
        $today = $this->entryOn($user, '2026-10-21');
        $this->assertNotNull($today);
        $this->assertSame(6.0, $today->candidate_grant_days);

        // 付与ルールの30か月の日数を9日に変える(今日の行の候補は9日になるが、今日の行は変えない。
        // 1年先(2027-10-21)の行は42か月の日数を10日に変えて、再計算が行われたことを確かめる)。
        $ruleId = PaidLeaveGrantRule::query()->where('work_style_id', $three->id)->value('id');
        PaidLeaveGrantRuleStep::query()->where('rule_id', $ruleId)->where('continuous_service_months', 30)->update(['grant_days' => 9]);
        PaidLeaveGrantRuleStep::query()->where('rule_id', $ruleId)->where('continuous_service_months', 42)->update(['grant_days' => 10]);

        Carbon::setTestNow('2026-10-21');
        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $todayAfter = $this->activeEntries($user)[0];
        $this->assertSame($today->id, $todayAfter->id);
        $this->assertSame(6.0, $todayAfter->candidate_grant_days);
        $this->assertSame(
            1,
            PaidLeaveScheduleEntry::query()
                ->where('user_id', $user->id)
                ->whereDate('scheduled_on', '2026-10-21')
                ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
                ->count(),
        );
        $this->assertSame(10.0, $this->entryOn($user, '2027-10-21')->candidate_grant_days);
    }

    public function test_roll_cancels_unconfirmed_entry_when_no_rule_matches_any_more(): void
    {
        $five = $this->fiveDayWorkStyle();
        $user = $this->userOn($five);
        $entry = $this->onlyActiveEntry($user);

        // 勤務形態のルールと全社共通ルールを無効にする。自動付与は有効のまま。
        PaidLeaveGrantRule::query()->update(['is_active' => false]);

        Carbon::setTestNow('2026-10-22');
        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $entry->fresh()->status);
        $this->assertSame([], $this->activeEntries($user));
    }
}
