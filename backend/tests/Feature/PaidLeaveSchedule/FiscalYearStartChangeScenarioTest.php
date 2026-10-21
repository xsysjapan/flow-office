<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\Attendance\Commands\DeleteCompanyCalendar;
use App\Domain\Attendance\Commands\SetDefaultCompanyCalendar;
use App\Domain\Attendance\Commands\UpdateCompanyCalendar;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Projectors\PaidLeaveFiscalYearSettingsProjector;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\CompanyCalendar;
use App\Models\PaidLeaveFiscalYearSetting;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 既定の会社カレンダーの年度開始日(有給の一斉付与日)の変更を、Commandの発行から付与予定の状態までを通して確かめる
 * シナリオテスト(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点3)。
 *
 * 前提: 今日=2026-10-21、全社共通ルール(マイグレーションの初期ルール。年度はじめ一斉付与・法定の写し)、
 * 週5日の勤務形態、入社日2025-12-01。年度開始4/1なら、今日から1年先までの付与予定は2027-04-01(名目30か月・12日)の1行。
 * 年度開始を10/1にすると、半年の日(2026-06-01)が年度開始日(2026-10-01)より早いので前倒しにならず、
 * 付与日は2026-06-01(名目6)・2026-10-01(名目18)・2027-10-01(名目30)となり、1年先までの行は2027-10-01の1行。
 */
class FiscalYearStartChangeScenarioTest extends TestCase
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

    private function createCalendar(string $name, int $month = 4, int $day = 1): CompanyCalendar
    {
        return $this->bus()->dispatch(new CreateCompanyCalendar(
            name: $name,
            weekStartsOn: 1,
            fiscalYearStartMonth: $month,
            fiscalYearStartDay: $day,
            createdByUserId: $this->admin->id,
        ));
    }

    private function updateCalendar(CompanyCalendar $calendar, string $name, int $month, int $day): void
    {
        $this->bus()->dispatch(new UpdateCompanyCalendar(
            companyCalendarId: $calendar->id,
            name: $name,
            weekStartsOn: 1,
            fiscalYearStartMonth: $month,
            fiscalYearStartDay: $day,
            holidayCalendarSourceId: null,
            updatedByUserId: $this->admin->id,
            allowDailyHolidayOverride: true,
        ));
    }

    private function userWithSchedule(): User
    {
        $workStyle = $this->bus()->dispatch(new CreateWorkStyle(
            attributes: [
                'code' => 'FIVE',
                'name' => 'FIVE',
                'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
                'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
                'prescribed_daily_minutes' => 480,
                'prescribed_weekly_minutes' => 2400,
                'weekly_scheduled_days' => 5,
                'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
                'is_shift_based' => false,
            ],
            createdByUserId: $this->admin->id,
        ));

        $user = User::factory()->create();
        $this->bus()->dispatch(new AssignUserWorkStyleForMonth(
            userId: $user->id,
            yearMonth: '2026-10',
            workStyleId: $workStyle->id,
            assignedByUserId: $this->admin->id,
        ));
        $this->bus()->dispatch(new SetUserHireDate(userId: $user->id, hireDate: '2025-12-01', changedByUserId: $this->admin->id));

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
            ->orderBy('nominal_service_months')
            ->get()
            ->map(fn (PaidLeaveScheduleEntry $entry) => [$entry->scheduled_on->toDateString(), $entry->nominal_service_months, $entry->candidate_grant_days])
            ->all();
    }

    private function setting(): PaidLeaveFiscalYearSetting
    {
        return PaidLeaveFiscalYearSetting::query()->findOrFail(PaidLeaveFiscalYearSetting::SINGLETON_ID);
    }

    public function test_changing_the_default_calendars_fiscal_year_start_moves_unconfirmed_entries(): void
    {
        $calendar = $this->createCalendar('標準カレンダー');
        $user = $this->userWithSchedule();
        $this->assertSame([['2027-04-01', 30, 12.0]], $this->activeEntries($user));
        $oldEntryId = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->value('id');

        $this->updateCalendar($calendar, '標準カレンダー', 10, 1);

        $this->assertSame([10, 1], [$this->setting()->fiscal_year_start_month, $this->setting()->fiscal_year_start_day]);
        $this->assertSame([['2027-10-01', 30, 12.0]], $this->activeEntries($user));
        // 2027-04-01の行は取り消され、新しい行が作られる。
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, PaidLeaveScheduleEntry::query()->findOrFail($oldEntryId)->status);

        // 年度開始を戻すと、付与日も戻る。
        $this->updateCalendar($calendar, '標準カレンダー', 4, 1);
        $this->assertSame([['2027-04-01', 30, 12.0]], $this->activeEntries($user));
    }

    public function test_granted_entries_are_not_touched_and_next_grant_follows_within_a_year(): void
    {
        $calendar = $this->createCalendar('標準カレンダー');
        $user = $this->userWithSchedule();
        $granted = PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id,
            'scheduled_on' => '2026-04-01',
            'category' => 'normal',
            'candidate_grant_days' => 10,
            'nominal_service_months' => 18,
            'status' => PaidLeaveScheduleEntry::STATUS_GRANTED,
        ]);

        // 付与済みの2026-04-01(名目18)を起点に、年度開始11/1の次回は同じ年の2026-11-01(名目30)。
        // 前回から1年以内(短い間隔)で、2027-04-01の行は取り消される。(今日2026-10-21より前の10/1にはしない)
        $this->updateCalendar($calendar, '標準カレンダー', 11, 1);

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_GRANTED, $granted->fresh()->status);
        $this->assertSame(
            [['2026-04-01', 18, 10.0], ['2026-11-01', 30, 12.0]],
            $this->activeEntries($user),
        );
    }

    public function test_renaming_the_calendar_does_not_reapply(): void
    {
        $calendar = $this->createCalendar('標準カレンダー');
        $user = $this->userWithSchedule();
        $before = $this->activeEntries($user);
        $entryIds = PaidLeaveScheduleEntry::query()->orderBy('id')->pluck('id')->all();
        $scheduleEventCount = DB::table('stored_events')->where('event_class', 'like', 'paid_leave_schedule.%')->count();
        $lastChangedEventId = $this->setting()->last_changed_event_id;

        Queue::fake();
        $this->updateCalendar($calendar, '名前だけ変えたカレンダー', 4, 1);

        Queue::assertNotPushed(ReapplyPaidLeaveSchedulePolicyJob::class);
        $this->assertSame($lastChangedEventId, $this->setting()->last_changed_event_id);
        $this->assertSame($before, $this->activeEntries($user));
        $this->assertSame($entryIds, PaidLeaveScheduleEntry::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($scheduleEventCount, DB::table('stored_events')->where('event_class', 'like', 'paid_leave_schedule.%')->count());
    }

    public function test_changing_the_fiscal_year_start_pushes_the_reapply_job_once(): void
    {
        $calendar = $this->createCalendar('標準カレンダー');

        Queue::fake();
        $this->updateCalendar($calendar, '標準カレンダー', 4, 16);
        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 1);

        // 同じ内容での更新は何も変えないので、再度は発行しない。
        $this->updateCalendar($calendar, '標準カレンダー', 4, 16);
        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 1);
    }

    public function test_view_follows_creation_default_change_and_deletion_of_calendars(): void
    {
        $first = $this->createCalendar('標準カレンダー');
        $this->assertSame([$first->id, 4, 1], [$this->setting()->company_calendar_id, $this->setting()->fiscal_year_start_month, $this->setting()->fiscal_year_start_day]);

        Queue::fake();

        // 既定でないカレンダーの作成・更新は、ビューも作り直しも変えない。
        $second = $this->createCalendar('別カレンダー', 10, 1);
        $this->updateCalendar($second, '別カレンダー(改)', 7, 1);
        $this->assertSame([$first->id, 4, 1], [$this->setting()->company_calendar_id, $this->setting()->fiscal_year_start_month, $this->setting()->fiscal_year_start_day]);
        Queue::assertNotPushed(ReapplyPaidLeaveSchedulePolicyJob::class);

        // 既定を切り替えると、新しい既定の最新の年度開始月日(更新後の7/1)に変わり、作り直す。
        $this->bus()->dispatch(new SetDefaultCompanyCalendar(companyCalendarId: $second->id, changedByUserId: $this->admin->id));
        $this->assertSame([$second->id, 7, 1], [$this->setting()->company_calendar_id, $this->setting()->fiscal_year_start_month, $this->setting()->fiscal_year_start_day]);
        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 1);

        // 同じ年度開始日のカレンダーへ既定を切り替えても、既定のカレンダーが変わるので作り直す。
        $this->updateCalendar($first, '標準カレンダー', 7, 1);
        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 1);
        $this->bus()->dispatch(new SetDefaultCompanyCalendar(companyCalendarId: $first->id, changedByUserId: $this->admin->id));
        $this->assertSame([$first->id, 7, 1], [$this->setting()->company_calendar_id, $this->setting()->fiscal_year_start_month, $this->setting()->fiscal_year_start_day]);
        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 2);

        // 既定でないカレンダーの削除は何も変えない。
        $this->bus()->dispatch(new DeleteCompanyCalendar(companyCalendarId: $second->id, deletedByUserId: $this->admin->id));
        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 2);
        $this->assertSame($first->id, $this->setting()->company_calendar_id);
    }

    public function test_view_rebuilds_to_the_same_state_from_events(): void
    {
        $calendar = $this->createCalendar('標準カレンダー');
        $this->updateCalendar($calendar, '標準カレンダー', 10, 1);
        $this->updateCalendar($calendar, '名前だけ', 10, 1);
        $before = $this->setting()->only(['company_calendar_id', 'fiscal_year_start_month', 'fiscal_year_start_day', 'last_changed_event_id']);
        $this->assertNotNull($before['last_changed_event_id']);

        PaidLeaveFiscalYearSetting::query()->delete();
        Artisan::call('event-sourcing:replay', ['projector' => [PaidLeaveFiscalYearSettingsProjector::class], '--force' => true]);

        $this->assertSame($before, $this->setting()->only(['company_calendar_id', 'fiscal_year_start_month', 'fiscal_year_start_day', 'last_changed_event_id']));
    }
}
