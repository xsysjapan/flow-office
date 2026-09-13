<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\Attendance\Commands\UpdateWorkStyle;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec.md 実装対象Phase C・論点7: hire_date/work_style変更をトリガーにした
 * `RecalculateScheduleOnConditionChangedReactor`の検証。過去確定(ここでは個別修正保護)
 * エントリが黙って上書きされないことも合わせて確認する。
 */
class RecalculateScheduleOnConditionChangedReactorTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function createWorkStyle(array $overrides = []): WorkStyle
    {
        return WorkStyle::query()->create(array_merge([
            'code' => 'NORMAL-'.uniqid(),
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ], $overrides));
    }

    private function seedNormalPolicy(): void
    {
        foreach ([6 => 10, 18 => 11, 30 => 12, 42 => 14, 54 => 16, 66 => 18] as $months => $days) {
            PaidLeaveGrantPolicy::query()->create([
                'version' => 'v1', 'continuous_service_months' => $months, 'grant_days' => $days,
                'effective_from' => '2020-01-01', 'is_active' => true,
            ]);
        }
    }

    public function test_changing_hire_date_recalculates_future_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createWorkStyle();

        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        // work_style割当は、割当自体のReactorトリガー(setUp時点の再計算)による
        // 意図しない先行生成を避けるため、テストのセットアップでは直接Eloquentで作成する
        // (割当変更トリガー自体の検証は本テストの対象外)。
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id,
            'year_month' => '2020-01',
            'work_style_id' => $workStyle->id,
            'assigned_by_user_id' => $user->id,
        ]);

        // 変更前の入社日(2026-09-13)を基準にしたエントリを事前生成しておく。
        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => 'e1', 'scheduledOn' => '2027-03-13', 'category' => 'normal', 'candidateGrantDays' => 10.0],
            ],
        ));
        $this->assertNotNull(PaidLeaveScheduleEntry::query()->find('e1'));

        // 入社日を1か月前倒しに変更する。Reactorが新しい記念日(2027-02-13)で再計算するはず。
        $this->bus()->dispatch(new SetUserHireDate(
            userId: $user->id,
            hireDate: '2026-08-13',
            changedByUserId: $user->id,
        ));

        $entries = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->get();
        $scheduledDates = $entries->pluck('scheduled_on')->map(fn ($d) => $d->toDateString())->all();

        $this->assertContains('2027-02-13', $scheduledDates);
        // 旧記念日のエントリはSupersededによりCancelledへ遷移し、有効なScheduleとしては
        // 残らない(Cancelled状態自体は履歴として残る)。
        $oldEntry = PaidLeaveScheduleEntry::query()->find('e1');
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $oldEntry->status);

        Carbon::setTestNow();
    }

    public function test_manually_edited_entry_is_protected_from_recalculation_on_hire_date_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createWorkStyle();

        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        // work_style割当は、割当自体のReactorトリガー(setUp時点の再計算)による
        // 意図しない先行生成を避けるため、テストのセットアップでは直接Eloquentで作成する
        // (割当変更トリガー自体の検証は本テストの対象外)。
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id,
            'year_month' => '2020-01',
            'work_style_id' => $workStyle->id,
            'assigned_by_user_id' => $user->id,
        ]);

        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => 'e1', 'scheduledOn' => '2027-03-13', 'category' => 'normal', 'candidateGrantDays' => 10.0],
            ],
        ));

        $this->bus()->dispatch(new ManuallyEditScheduleEntry(
            userId: $user->id,
            scheduleEntryId: 'e1',
            changes: ['candidateGrantDays' => 12.0],
            reason: '個別事情による調整',
            operatorUserId: $user->id,
        ));

        $this->bus()->dispatch(new SetUserHireDate(
            userId: $user->id,
            hireDate: '2026-08-13',
            changedByUserId: $user->id,
        ));

        // 個別修正済みエントリは黙って上書き・取消されない(依頼書§28)。
        $entry = PaidLeaveScheduleEntry::query()->findOrFail('e1');
        $this->assertNotSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, $entry->status);
        $this->assertEquals(12.0, (float) $entry->candidate_grant_days);
        $this->assertTrue((bool) $entry->is_manually_overridden);

        Carbon::setTestNow();
    }

    public function test_updating_work_style_recalculates_schedule_for_assigned_users(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createWorkStyle(['weekly_scheduled_days' => null, 'prescribed_weekly_minutes' => 800, 'is_shift_based' => false]);

        // マイグレーションでシードされた全社共通(一斉付与)ルールより優先させ、
        // 従来通りの周年サイクル・法定Policy相当の候補日1件のみを生成させる
        // (本テストの検証対象は「区分再判定」であり、一斉付与アルゴリズムではないため)。
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => 'work_style固有(周年)',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);
        foreach ([6 => 10, 18 => 11, 30 => 12, 42 => 14, 54 => 16, 66 => 18] as $months => $days) {
            $rule->steps()->create(['continuous_service_months' => $months, 'grant_days' => $days]);
        }

        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        // work_style割当は、割当自体のReactorトリガー(setUp時点の再計算)による
        // 意図しない先行生成を避けるため、テストのセットアップでは直接Eloquentで作成する
        // (割当変更トリガー自体の検証は本テストの対象外)。
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id,
            'year_month' => '2020-01',
            'work_style_id' => $workStyle->id,
            'assigned_by_user_id' => $user->id,
        ]);

        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => 'e1', 'scheduledOn' => '2027-03-13', 'category' => 'needs_review', 'candidateGrantDays' => 0.0],
            ],
        ));

        // 週所定労働日数を追加登録し、通常付与に該当するよう変更する。
        $this->bus()->dispatch(new UpdateWorkStyle(
            workStyleId: $workStyle->id,
            attributes: array_merge($workStyle->refresh()->toArray(), ['weekly_scheduled_days' => 5]),
            updatedByUserId: $user->id,
        ));

        $entries = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('normal', $entries->first()->category);

        Carbon::setTestNow();
    }

    public function test_assigning_work_style_for_month_recalculates_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createWorkStyle();

        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);

        // work_style割当自体をCommandBus経由で行い、Reactorが自動的にScheduleを
        // 生成することを確認する(依頼書§27「新入社員登録時も同じロジックで展開」に相当)。
        $this->bus()->dispatch(new \App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth(
            userId: $user->id,
            yearMonth: '2020-01',
            workStyleId: $workStyle->id,
            assignedByUserId: $user->id,
        ));

        $entries = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->get();
        $this->assertGreaterThanOrEqual(1, $entries->count());
        $this->assertContains('2027-03-13', $entries->pluck('scheduled_on')->map(fn ($d) => $d->toDateString())->all());

        Carbon::setTestNow();
    }
}
