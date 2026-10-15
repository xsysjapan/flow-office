<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\AllocateUnallocatedPaidLeaveUsages;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 付与時の未充当の充当のシナリオ(論点19 決定10-1)。
 * 残数0で承認(未充当) → 付与 → Reactorで自動充当 → 残高。Reactorの再実行で重複しない。
 */
class PaidLeaveGrantTimeAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function usage(User $user, string $usedOn, float $days): string
    {
        $approver = User::factory()->create();
        $usageId = $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id, workflowRequestId: null, attendanceDayId: null, usedOn: $usedOn, usedDays: $days,
            usageType: 'full', paidLeaveRequestId: (string) \Illuminate\Support\Str::uuid(), approverUserId: $approver->id,
        ));
        $this->bus()->dispatch(new ConfirmPaidLeaveUsage($user->id, $usageId, $approver->id));

        return $usageId;
    }

    private function allocatedCount(User $user): int
    {
        return EloquentStoredEvent::query()->where('aggregate_uuid', $user->id)->where('event_class', 'paid_leave_account.usage_allocated')->count();
    }

    public function test_usage_approved_with_no_balance_is_allocated_when_a_grant_is_recorded_later(): void
    {
        $user = User::factory()->create();
        $this->usage($user, '2026-08-10', 2.0);

        $balance = DB::table('paid_leave_balances')->where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(2.0, (float) $balance->unallocated_days, 0.000001);
        $this->assertSame(0, $this->allocatedCount($user));

        // 付与日は対象日より後(有効期間と対象日の関係は問わない)。
        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-10-01', '2028-09-30', 10.0, null));

        $this->assertSame(1, $this->allocatedCount($user));
        $balance = DB::table('paid_leave_balances')->where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(0.0, (float) $balance->unallocated_days, 0.000001);
        $this->assertEqualsWithDelta(8.0, (float) $balance->available_days, 0.000001);
    }

    public function test_oldest_confirmed_is_allocated_first_and_the_rest_stays_unallocated(): void
    {
        $user = User::factory()->create();
        $this->usage($user, '2026-08-12', 2.0);
        $this->usage($user, '2026-08-10', 2.0);

        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-10-01', '2028-09-30', 3.0, null));

        $allocated = DB::table('stored_events')->where('aggregate_uuid', $user->id)->where('event_class', 'paid_leave_account.usage_allocated')
            ->orderBy('id')->pluck('event_properties')->map(fn ($p) => json_decode($p, true))->all();
        $this->assertCount(2, $allocated);
        $this->assertEqualsWithDelta(2.0, $allocated[0]['allocatedDays'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $allocated[1]['allocatedDays'], 0.000001);
        $balance = DB::table('paid_leave_balances')->where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(1.0, (float) $balance->unallocated_days, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->available_days, 0.000001);
    }

    public function test_rerunning_the_reactor_command_does_not_duplicate_allocations(): void
    {
        $user = User::factory()->create();
        $this->usage($user, '2026-08-10', 2.0);
        $grantId = $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-10-01', '2028-09-30', 10.0, null));
        $this->assertSame(1, $this->allocatedCount($user));
        $count = EloquentStoredEvent::query()->where('aggregate_uuid', $user->id)->count();

        $this->bus()->dispatch(new AllocateUnallocatedPaidLeaveUsages($user->id, $grantId, viaReactor: true));

        $this->assertSame(1, $this->allocatedCount($user));
        $this->assertSame($count, EloquentStoredEvent::query()->where('aggregate_uuid', $user->id)->count());
        $balance = DB::table('paid_leave_balances')->where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(8.0, (float) $balance->available_days, 0.000001);
    }

    public function test_other_users_unallocated_usage_is_not_touched(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->usage($other, '2026-08-10', 1.0);

        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-10-01', '2028-09-30', 10.0, null));

        $this->assertSame(0, $this->allocatedCount($other));
    }
}
