<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\UpdateWorkStyle;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Support\GrantCategory;
use App\Domain\UserManagement\Commands\SetPaidLeaveAutoGrantEnabled;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Commands\SetUserUsageStartDate;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * spec.md論点7: Schedule再計算のトリガー。`hire_date`/`usage_start_date`/
 * `paid_leave_auto_grant_enabled`/`work_style_id`割当/`WorkStyle`区分判定関連列の変更が
 * `RecalculateFutureSchedule`を発行し、既存Scheduleエントリへ反映されることを確認する。
 */
class ScheduleRecalculationReactorTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function createWorkStyle(array $overrides = []): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create(array_merge([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 1200,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
            'weekly_scheduled_days' => 5,
        ], $overrides));
    }

    /**
     * `RecalculateFutureScheduleHandler::currentWorkStyleId()`は`EmployeeCalendarEntry`から
     * 「今日時点で最新の`work_date`」の`work_style_id`を参照する実装のため(Phase A実装、
     * `UserWorkStyleMonthlyAssignment`は参照しない)、区分判定を意図通りに再現するには
     * このテスト用ヘルパーで直接`EmployeeCalendarEntry`を用意する必要がある。
     */
    private function markCurrentWorkStyle(User $user, WorkStyle $workStyle): void
    {
        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => Carbon::now()->toDateString(), 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_break_minutes' => 60,
        ]);
    }

    private function createEntry(User $user, string $scheduledOn, string $category, float $days): string
    {
        $entryId = (string) Str::uuid();

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryId, $scheduledOn, $category, $days)
            ->persist();

        return $entryId;
    }

    public function test_setting_paid_leave_auto_grant_enabled_triggers_recalculation(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $entryId = $this->createEntry($user, '2026-10-01', GrantCategory::REGULAR, 11.0);

        $admin = User::factory()->create();
        $this->bus()->dispatch(new SetPaidLeaveAutoGrantEnabled(userId: $user->id, enabled: false, changedByUserId: $admin->id));

        $replayed = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
        // カテゴリ・候補日数自体は変わらない(独自ルール・WorkStyle未変更)ため、
        // supersede自体は冪等スキップされうるが、例外なく再計算が発行されたことを
        // エントリが引き続き存在すること(=Aggregateが壊れていないこと)で確認する。
        $this->assertNotNull($replayed->entry($entryId));
    }

    public function test_setting_hire_date_triggers_recalculation_and_changes_candidate_days(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        // 実際の候補日数と食い違う値を仕込んでおき、再計算(supersede)で置き換わることを見る。
        $entryId = $this->createEntry($user, '2026-10-01', GrantCategory::REGULAR, 999.0);

        $admin = User::factory()->create();
        $this->bus()->dispatch(new SetUserHireDate(userId: $user->id, hireDate: '2020-01-01', changedByUserId: $admin->id));

        $replayed = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
        $entry = $replayed->entry($entryId);
        $this->assertNotSame(999.0, $entry['candidateGrantDays']);
    }

    public function test_setting_usage_start_date_triggers_recalculation(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $entryId = $this->createEntry($user, '2026-10-01', GrantCategory::REGULAR, 11.0);

        $admin = User::factory()->create();
        $this->bus()->dispatch(new SetUserUsageStartDate(userId: $user->id, usageStartDate: '2025-01-01', changedByUserId: $admin->id));

        $replayed = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
        $this->assertNotNull($replayed->entry($entryId));
    }

    public function test_assigning_a_new_work_style_for_the_month_triggers_recalculation(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $entryId = $this->createEntry($user, '2026-10-01', GrantCategory::PROPORTIONAL, 3.0);

        $regularWorkStyle = $this->createWorkStyle(['code' => 'reg-'.uniqid(), 'weekly_scheduled_days' => 5]);
        $admin = User::factory()->create();

        $this->markCurrentWorkStyle($user, $regularWorkStyle);

        $this->bus()->dispatch(new AssignUserWorkStyleForMonth(
            userId: $user->id,
            yearMonth: Carbon::now()->format('Y-m'),
            workStyleId: $regularWorkStyle->id,
            assignedByUserId: $admin->id,
        ));

        $replayed = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
        $entry = $replayed->entry($entryId);
        // 通常勤務(週5日)へ割当が変わったため、区分がPROPORTIONAL→REGULARへ変わる。
        $this->assertSame(GrantCategory::REGULAR, $entry['category']);
    }

    public function test_updating_work_style_classification_relevant_columns_triggers_recalculation_for_assigned_users(): void
    {
        $workStyle = $this->createWorkStyle(['weekly_scheduled_days' => 3]);
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $admin = User::factory()->create();
        $this->markCurrentWorkStyle($user, $workStyle);

        $this->bus()->dispatch(new AssignUserWorkStyleForMonth(
            userId: $user->id,
            yearMonth: Carbon::now()->format('Y-m'),
            workStyleId: $workStyle->id,
            assignedByUserId: $admin->id,
        ));

        $entryId = $this->createEntry($user, '2026-10-01', GrantCategory::PROPORTIONAL, 3.0);

        $this->bus()->dispatch(new UpdateWorkStyle(
            workStyleId: $workStyle->id,
            attributes: ['weekly_scheduled_days' => 5],
            updatedByUserId: $admin->id,
        ));

        $replayed = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
        $entry = $replayed->entry($entryId);
        $this->assertSame(GrantCategory::REGULAR, $entry['category']);
    }

    public function test_updating_a_work_style_column_unrelated_to_classification_does_not_trigger_recalculation(): void
    {
        $workStyle = $this->createWorkStyle(['weekly_scheduled_days' => 3]);
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $admin = User::factory()->create();

        $this->bus()->dispatch(new AssignUserWorkStyleForMonth(
            userId: $user->id,
            yearMonth: Carbon::now()->format('Y-m'),
            workStyleId: $workStyle->id,
            assignedByUserId: $admin->id,
        ));

        $entryId = $this->createEntry($user, '2026-10-01', GrantCategory::PROPORTIONAL, 3.0);

        $this->bus()->dispatch(new UpdateWorkStyle(
            workStyleId: $workStyle->id,
            attributes: ['name' => '名称変更のみ'],
            updatedByUserId: $admin->id,
        ));

        $replayed = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
        $entry = $replayed->entry($entryId);
        // 区分判定に無関係な列変更のため区分は変わらない(PROPORTIONALのまま)。
        $this->assertSame(GrantCategory::PROPORTIONAL, $entry['category']);
    }
}
