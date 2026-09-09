<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\OverrideScheduleAssessment;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Support\GrantCategory;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use App\Models\AttendanceDay;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `PaidLeaveScheduleAggregate`のCommand→Handler→Event→Projectorの一連のパイプラインを
 * CommandBus経由で確認し、`paid_leave_schedule_entries`(Projection Table)が正しく更新
 * されることを検証する(docs/changesets/20260906-paid-leave-schedule-assessment/spec.md
 * Phase B)。Projectorを直接呼ばないことで`event-sourcing:replay`によるProjection再生成が
 * 成立することも間接的に裏付ける(最後のテストで直接検証する)。
 */
class PaidLeaveScheduleEntryProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function createWorkStyle(): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    private function seedFullAttendance(User $user, WorkStyle $workStyle, Carbon $start, int $days): void
    {
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            EmployeeCalendarEntry::query()->create([
                'user_id' => $user->id, 'work_date' => $date->toDateString(), 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_break_minutes' => 60,
            ]);

            AttendanceDay::query()->create([
                'user_id' => $user->id, 'work_date' => $date->toDateString(),
                'status' => 'clocked_out', 'source' => 'live',
            ]);
        }
    }

    public function test_entry_created_event_populates_scheduled_row(): void
    {
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryId, '2026-10-01', GrantCategory::REGULAR, 11.0)
            ->persist();

        $row = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('2026-10-01', $row->scheduled_on->toDateString());
        $this->assertSame(GrantCategory::REGULAR, $row->category);
        $this->assertSame(11.0, (float) $row->candidate_grant_days);
        $this->assertSame(ScheduleEntryStatus::SCHEDULED, $row->status);
        $this->assertNull($row->assessment_final_result);
    }

    public function test_assessment_recorded_updates_assessment_columns_and_status(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $workStyle = $this->createWorkStyle();
        $periodEnd = Carbon::parse('2026-10-01');
        $this->seedFullAttendance($user, $workStyle, $periodEnd->copy()->subYear(), 300);

        $entryId = (string) Str::uuid();
        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryId, $periodEnd->toDateString(), GrantCategory::REGULAR, 11.0)
            ->persist();

        $this->bus()->dispatch(new RunAttendanceRateAssessment(userId: $user->id, entryId: $entryId));

        $row = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $row->status);
        $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $row->assessment_automatic_result);
        $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $row->assessment_final_result);
        $this->assertSame(300, $row->assessment_attendance_days);
        $this->assertNotNull($row->assessment_period_start);
        $this->assertNotNull($row->assessment_policy_version);
    }

    public function test_override_persists_final_result_and_reason(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $entryId = (string) Str::uuid();

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryId, '2026-10-01', GrantCategory::REGULAR, 11.0)
            ->recordAssessment(
                entryId: $entryId,
                periodStart: '2025-10-01',
                periodEnd: '2026-09-30',
                denominatorDays: 0,
                attendanceDays: 0,
                excludedDays: 0,
                attendanceRate: null,
                policyVersion: 'v1',
                automaticResult: ScheduleEntryStatus::NEEDS_REVIEW,
            )
            ->persist();

        $admin = User::factory()->create();

        $this->bus()->dispatch(new OverrideScheduleAssessment(
            userId: $user->id,
            entryId: $entryId,
            finalResult: ScheduleEntryStatus::ELIGIBLE,
            reason: '育休からの復帰を確認済み',
            operatorUserId: $admin->id,
        ));

        $row = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $row->status);
        $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $row->assessment_final_result);
        $this->assertSame('育休からの復帰を確認済み', $row->assessment_override_reason);
        $this->assertSame($admin->id, $row->manual_override_by_user_id);
        $this->assertNotNull($row->manual_override_at);
        $this->assertTrue($row->needsReviewDueToConflict() === false);
    }

    public function test_apply_scheduled_grants_marks_entry_granted_with_grant_id(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $entryId = (string) Str::uuid();

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryId, '2026-10-01', GrantCategory::REGULAR, 11.0)
            ->recordAssessment(
                entryId: $entryId,
                periodStart: '2025-10-01',
                periodEnd: '2026-09-30',
                denominatorDays: 200,
                attendanceDays: 190,
                excludedDays: 0,
                attendanceRate: 95.0,
                policyVersion: 'v1',
                automaticResult: ScheduleEntryStatus::ELIGIBLE,
            )
            ->persist();

        $admin = User::factory()->create();
        $grantedIds = $this->bus()->dispatch(new ApplyScheduledGrants(
            userId: $user->id,
            entryIds: [$entryId],
            operatorUserId: $admin->id,
        ));

        $row = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame(ScheduleEntryStatus::GRANTED, $row->status);
        $this->assertSame($grantedIds[$entryId], $row->granted_paid_leave_grant_id);
    }

    /**
     * 回帰テスト(spec.md論点14): `PaidLeaveScheduleAggregate`と`PaidLeaveAccountAggregate`の
     * AggregateIdが同じ生userIdを共有していた旧実装では、`ApplyScheduledGrantsHandler`が
     * 「Schedule Aggregateをpersist → 同一userIdでAccount AggregateのGrantを発行・persist →
     * 再度Schedule Aggregateをretrieveしてpersist」という順序で両Aggregateを交互に操作した際、
     * 両者が`stored_events.aggregate_uuid`を共有するために発生した`CouldNotPersistAggregate`
     * (楽観的排他の誤発火)が起きないことを検証する。userIdから決定的に導出した別UUIDへ
     * AggregateIdを分離した本修正が正しく機能していれば、複数エントリの一括付与(Schedule
     * persist → Account persist → Schedule persistをエントリ数分繰り返す)が例外なく完了する。
     */
    public function test_applying_multiple_scheduled_grants_does_not_trigger_cross_aggregate_uuid_collision(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $admin = User::factory()->create();

        $entryIdA = (string) Str::uuid();
        $entryIdB = (string) Str::uuid();

        $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryIdA, '2026-10-01', GrantCategory::REGULAR, 11.0)
            ->recordAssessment(
                entryId: $entryIdA,
                periodStart: '2025-10-01',
                periodEnd: '2026-09-30',
                denominatorDays: 200,
                attendanceDays: 190,
                excludedDays: 0,
                attendanceRate: 95.0,
                policyVersion: 'v1',
                automaticResult: ScheduleEntryStatus::ELIGIBLE,
            );
        $aggregate->createEntry($user->id, $entryIdB, '2027-04-01', GrantCategory::REGULAR, 12.0)
            ->recordAssessment(
                entryId: $entryIdB,
                periodStart: '2026-04-01',
                periodEnd: '2027-03-31',
                denominatorDays: 200,
                attendanceDays: 190,
                excludedDays: 0,
                attendanceRate: 95.0,
                policyVersion: 'v1',
                automaticResult: ScheduleEntryStatus::ELIGIBLE,
            );
        $aggregate->persist();

        // 例外(CouldNotPersistAggregate)が発生しないことそのものがこのテストの主張。
        $grantedIds = $this->bus()->dispatch(new ApplyScheduledGrants(
            userId: $user->id,
            entryIds: [$entryIdA, $entryIdB],
            operatorUserId: $admin->id,
        ));

        $this->assertCount(2, $grantedIds);

        $rowA = PaidLeaveScheduleEntry::query()->findOrFail($entryIdA);
        $rowB = PaidLeaveScheduleEntry::query()->findOrFail($entryIdB);
        $this->assertSame(ScheduleEntryStatus::GRANTED, $rowA->status);
        $this->assertSame(ScheduleEntryStatus::GRANTED, $rowB->status);
        $this->assertSame($grantedIds[$entryIdA], $rowA->granted_paid_leave_grant_id);
        $this->assertSame($grantedIds[$entryIdB], $rowB->granted_paid_leave_grant_id);

        // Schedule Aggregateを改めてretrieveでき、両エントリがGrantedとして正しくreplayされる
        // (AggregateId分離後もイベントストリームの通し番号が破綻していないことの裏付け)。
        $replayed = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
        $this->assertSame(ScheduleEntryStatus::GRANTED, $replayed->entry($entryIdA)['status']);
        $this->assertSame(ScheduleEntryStatus::GRANTED, $replayed->entry($entryIdB)['status']);
    }

    /**
     * 個別修正済みエントリが新算出結果と食い違いNeedsReviewへ押し出された場合、
     * `needsReviewDueToConflict()`(専用列を持たない「変更・要確認」導出、spec.md論点12)が
     * trueを返すことを確認する。
     */
    public function test_conflicting_supersede_after_manual_edit_is_flagged_as_needs_review_conflict(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $admin = User::factory()->create();
        $entryId = (string) Str::uuid();

        $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryId, '2026-10-01', GrantCategory::REGULAR, 11.0);
        $aggregate->manuallyEditEntry(
            entryId: $entryId,
            category: null,
            candidateGrantDays: 15.0,
            reason: '特別加算',
            byUserId: $admin->id,
            at: Carbon::now()->toIso8601String(),
        );
        $aggregate->persist();

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->supersedeEntry($entryId, 'work_style変更', GrantCategory::PROPORTIONAL, 7.0)
            ->persist();

        $row = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame(ScheduleEntryStatus::NEEDS_REVIEW, $row->status);
        $this->assertTrue($row->needsReviewDueToConflict());
        // 個別修正内容自体(候補日数)は保持される(spec.md論点7)。
        $this->assertSame(15.0, (float) $row->candidate_grant_days);
    }

    public function test_event_sourcing_replay_rebuilds_projection_from_stored_events(): void
    {
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryId, '2026-10-01', GrantCategory::REGULAR, 11.0)
            ->persist();

        $this->assertDatabaseHas('paid_leave_schedule_entries', ['id' => $entryId, 'status' => ScheduleEntryStatus::SCHEDULED]);

        PaidLeaveScheduleEntry::query()->truncate();
        $this->assertDatabaseMissing('paid_leave_schedule_entries', ['id' => $entryId]);

        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $row = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame(GrantCategory::REGULAR, $row->category);
        $this->assertSame(ScheduleEntryStatus::SCHEDULED, $row->status);
    }
}
