<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Commands\SetUserUsageStartDate;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 有給口座・付与予定のAggregateIdを社員IDから分離したことのシナリオテスト
 * (docs/changesets/20261021-paid-leave-aggregate-stream-id/spec.md)。
 * 社員(UserAggregate)・有給口座・付与予定が1つのストリームを共有していたため起きた版の衝突
 * (CouldNotPersistAggregate)が起きないこと、イベントが社員IDを持つこと、履歴が今までどおり読めることを確かめる。
 */
class PaidLeaveStreamSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21');
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

    private function eligibleEntry(User $user, string $scheduledOn): string
    {
        $entryId = (string) Str::uuid();
        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => $entryId, 'scheduledOn' => $scheduledOn, 'category' => 'normal', 'candidateGrantDays' => 10.0],
            ],
        ));
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: $entryId,
            assessmentId: (string) Str::uuid(),
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: 18,
            excludedDays: 0,
            attendanceRate: 90.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
        ));

        return $entryId;
    }

    private function confirmedUsage(User $user, string $usedOn, float $days): string
    {
        $approver = User::factory()->create();
        $usageId = $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id, workflowRequestId: null, attendanceDayId: null, usedOn: $usedOn, usedDays: $days,
            usageType: 'full', paidLeaveRequestId: (string) Str::uuid(), approverUserId: $approver->id,
        ));
        $this->bus()->dispatch(new ConfirmPaidLeaveUsage($user->id, $usageId, $approver->id));

        return $usageId;
    }

    public function test_r1_bulk_grant_of_two_entries_for_the_same_user_does_not_conflict(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();
        $first = $this->eligibleEntry($user, '2026-10-01');
        $second = $this->eligibleEntry($user, '2027-10-01');

        $result = $this->bus()->dispatch(new ApplyScheduledGrants($user->id, [$first, $second], $operator->id));

        $this->assertSame([], $result['failed']);
        $this->assertCount(2, $result['granted']);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_GRANTED, PaidLeaveScheduleEntry::query()->findOrFail($first)->status);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_GRANTED, PaidLeaveScheduleEntry::query()->findOrFail($second)->status);
        $this->assertSame(2, EloquentStoredEvent::query()
            ->where('aggregate_uuid', PaidLeaveAccountAggregate::streamIdFor($user->id))
            ->where('event_class', 'paid_leave_account.grant_created')
            ->count());
    }

    public function test_r2_user_update_then_schedule_recalculation_then_manual_grant_does_not_conflict(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => (string) Str::uuid(), 'scheduledOn' => '2027-01-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
            ],
        ));

        // 社員の更新 → Reactorによる付与予定の再計算(付与予定のストリームへ書く)。
        $this->bus()->dispatch(new SetUserHireDate(userId: $user->id, hireDate: '2024-01-01', changedByUserId: $admin->id));
        $this->bus()->dispatch(new SetUserUsageStartDate(userId: $user->id, usageStartDate: '2027-08-01', changedByUserId: $admin->id));
        // 手動の付与(有給口座のストリームへ書く)。
        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-10-01', '2028-09-30', 10.0, null));
        // 付与の後にもう一度、社員の更新と再計算。
        $this->bus()->dispatch(new SetUserUsageStartDate(userId: $user->id, usageStartDate: '2027-09-01', changedByUserId: $admin->id));

        $this->assertSame(1, EloquentStoredEvent::query()
            ->where('aggregate_uuid', PaidLeaveAccountAggregate::streamIdFor($user->id))
            ->where('event_class', 'paid_leave_account.grant_created')
            ->count());
        $this->assertGreaterThan(1, EloquentStoredEvent::query()
            ->where('aggregate_uuid', PaidLeaveScheduleAggregate::streamIdFor($user->id))
            ->count());
        // 社員のストリームには社員のイベントだけがある。
        $this->assertSame(0, EloquentStoredEvent::query()
            ->where('aggregate_uuid', $user->id)
            ->where(fn ($q) => $q->where('event_class', 'like', 'paid_leave_account.%')->orWhere('event_class', 'like', 'paid_leave_schedule.%'))
            ->count());
    }

    public function test_r3_grant_allocates_unallocated_usage_in_the_new_account_stream(): void
    {
        $user = User::factory()->create();
        $this->confirmedUsage($user, '2026-08-10', 2.0);

        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-10-01', '2028-09-30', 10.0, null));

        $allocated = EloquentStoredEvent::query()
            ->where('aggregate_uuid', PaidLeaveAccountAggregate::streamIdFor($user->id))
            ->where('event_class', 'paid_leave_account.usage_allocated')
            ->get();
        $this->assertCount(1, $allocated);
        $this->assertSame($user->id, $allocated->first()->event_properties['userId']);
        $balance = DB::table('paid_leave_balances')->where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(0.0, (float) $balance->unallocated_days, 0.000001);
        $this->assertEqualsWithDelta(8.0, (float) $balance->available_days, 0.000001);
    }

    public function test_r4_events_use_a_separate_aggregate_uuid_and_carry_the_user_id(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();
        $entryId = $this->eligibleEntry($user, '2026-10-01');
        $this->bus()->dispatch(new ApplyScheduledGrants($user->id, [$entryId], $operator->id));
        $this->confirmedUsage($user, '2026-11-10', 1.0);

        $events = EloquentStoredEvent::query()
            ->where(fn ($q) => $q->where('event_class', 'like', 'paid_leave_account.%')->orWhere('event_class', 'like', 'paid_leave_schedule.%'))
            ->get();
        $this->assertGreaterThan(0, $events->count());

        foreach ($events as $event) {
            $this->assertNotSame($user->id, $event->aggregate_uuid, $event->event_class);
            $this->assertSame($user->id, $event->event_properties['userId'], $event->event_class);
            $expected = str_starts_with($event->event_class, 'paid_leave_account.')
                ? PaidLeaveAccountAggregate::streamIdFor($user->id)
                : PaidLeaveScheduleAggregate::streamIdFor($user->id);
            $this->assertSame($expected, $event->aggregate_uuid, $event->event_class);
        }
        $this->assertNotSame(
            PaidLeaveAccountAggregate::streamIdFor($user->id),
            PaidLeaveScheduleAggregate::streamIdFor($user->id),
        );
    }

    public function test_r4_history_api_returns_all_paid_leave_account_events(): void
    {
        $employee = User::factory()->create();
        $this->bus()->dispatch(new GrantPaidLeave($employee->id, '2026-08-01', '2028-07-31', 10.0, '初回付与'));
        $this->confirmedUsage($employee, '2026-08-10', 1.0);

        $response = $this->actingAs($employee)->getJson('/api/paid-leave/history/mine');

        $response->assertOk();
        $types = collect($response->json())->pluck('event_type');
        // grant_created・usage_designated・usage_confirmed・usage_allocated の4件(旧実装と同じ)。
        foreach (['paid_leave_account.grant_created', 'paid_leave_account.usage_designated', 'paid_leave_account.usage_confirmed', 'paid_leave_account.usage_allocated'] as $type) {
            $this->assertSame(1, $types->filter(fn ($t) => $t === $type)->count(), $type);
        }
        $this->assertSame(
            EloquentStoredEvent::query()->where('aggregate_uuid', PaidLeaveAccountAggregate::streamIdFor($employee->id))->count(),
            $types->filter(fn ($t) => str_starts_with($t, 'paid_leave_account.'))->count(),
        );
    }
}
