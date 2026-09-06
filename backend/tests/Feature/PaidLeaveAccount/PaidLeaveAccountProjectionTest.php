<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\RevokePaidLeaveGrant;
use App\Models\PaidLeaveBalance;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveUsage;
use App\Models\PaidLeaveUsageAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PaidLeaveAccountAggregate(Phase 3)のCommand→Handler→Aggregate→Event→Projectorの
 * 一連のパイプラインを通し、`paid_leave_grants`/`paid_leave_usages`/
 * `paid_leave_usage_allocations`/`paid_leave_balances`が正しく更新されることを検証する。
 * Projectorを直接呼ばず必ずCommandBus経由で確認することで、`event-sourcing:replay`による
 * Projection再生成が成立することも間接的に裏付ける。
 */
class PaidLeaveAccountProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    public function test_grant_created_populates_grant_row_and_balance(): void
    {
        $user = User::factory()->create();

        $grantId = $this->bus()->dispatch(new GrantPaidLeave(
            userId: $user->id,
            grantedOn: '2026-04-01',
            expiresOn: '2028-04-01',
            grantedDays: 10.0,
            grantReason: '定期付与',
        ));

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals($user->id, $grant->user_id);
        $this->assertEquals(10.0, (float) $grant->granted_days);
        $this->assertEquals(0.0, (float) $grant->allocated_days);
        $this->assertEquals(10.0, (float) $grant->remaining_days);
        $this->assertEquals('active', $grant->status);

        $balance = PaidLeaveBalance::query()->findOrFail($user->id);
        $this->assertEquals(10.0, (float) $balance->available_days);
        $this->assertEquals(0.0, (float) $balance->pending_days);
        $this->assertEquals(0.0, (float) $balance->unallocated_days);
    }

    public function test_usage_designated_creates_no_allocation_row_yet(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-04-01', '2028-04-01', 10.0, null));

        $usageId = $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id,
            workflowRequestId: 'wf-1',
            attendanceDayId: null,
            usedOn: '2026-05-01',
            usedDays: 1.0,
        ));

        $usage = PaidLeaveUsage::query()->where('usage_id', $usageId)->firstOrFail();
        $this->assertFalse($usage->confirmed);
        $this->assertFalse($usage->cancelled);
        $this->assertNull($usage->paid_leave_grant_id);

        $this->assertSame(0, PaidLeaveUsageAllocation::query()->where('usage_id', $usageId)->count());

        $balance = PaidLeaveBalance::query()->findOrFail($user->id);
        $this->assertEquals(10.0, (float) $balance->available_days);
        $this->assertEquals(1.0, (float) $balance->pending_days, 'designate直後は未確定Usageとしてpending_daysに計上される');
        $this->assertEquals(0.0, (float) $balance->unallocated_days);
    }

    public function test_usage_confirmed_allocates_against_grant_and_updates_balance(): void
    {
        $user = User::factory()->create();

        $grantId = $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-04-01', '2028-04-01', 10.0, null));

        $usageId = $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id,
            workflowRequestId: 'wf-1',
            attendanceDayId: null,
            usedOn: '2026-05-01',
            usedDays: 3.0,
        ));

        $this->bus()->dispatch(new ConfirmPaidLeaveUsage($user->id, $usageId, $user->id));

        $allocation = PaidLeaveUsageAllocation::query()
            ->where('usage_id', $usageId)
            ->where('grant_id', $grantId)
            ->firstOrFail();
        $this->assertEquals(3.0, (float) $allocation->allocated_days);

        $usage = PaidLeaveUsage::query()->where('usage_id', $usageId)->firstOrFail();
        $this->assertTrue($usage->confirmed);
        $this->assertEquals($grantId, $usage->paid_leave_grant_id);

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(3.0, (float) $grant->allocated_days);
        $this->assertEquals(7.0, (float) $grant->remaining_days);

        $balance = PaidLeaveBalance::query()->findOrFail($user->id);
        $this->assertEquals(7.0, (float) $balance->available_days);
        $this->assertEquals(0.0, (float) $balance->pending_days);
        $this->assertEquals(0.0, (float) $balance->unallocated_days);
    }

    public function test_usage_cancelled_releases_allocation_and_restores_balance(): void
    {
        $user = User::factory()->create();

        $grantId = $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-04-01', '2028-04-01', 10.0, null));

        $usageId = $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id,
            workflowRequestId: 'wf-1',
            attendanceDayId: null,
            usedOn: '2026-05-01',
            usedDays: 3.0,
        ));

        $this->bus()->dispatch(new ConfirmPaidLeaveUsage($user->id, $usageId, $user->id));
        $this->bus()->dispatch(new CancelPaidLeaveUsage($user->id, $usageId, $user->id, '取消'));

        $this->assertSame(0, PaidLeaveUsageAllocation::query()->where('usage_id', $usageId)->count());

        $usage = PaidLeaveUsage::query()->where('usage_id', $usageId)->firstOrFail();
        $this->assertTrue($usage->cancelled);
        $this->assertNull($usage->paid_leave_grant_id);

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(0.0, (float) $grant->allocated_days);
        $this->assertEquals(10.0, (float) $grant->remaining_days);

        $balance = PaidLeaveBalance::query()->findOrFail($user->id);
        $this->assertEquals(10.0, (float) $balance->available_days);
        $this->assertEquals(0.0, (float) $balance->pending_days);
        $this->assertEquals(0.0, (float) $balance->unallocated_days);
    }

    public function test_grant_revoked_removes_its_allocations_and_restores_balance(): void
    {
        $user = User::factory()->create();

        $grantId = $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-04-01', '2028-04-01', 10.0, null));

        $usageId = $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id,
            workflowRequestId: 'wf-1',
            attendanceDayId: null,
            usedOn: '2026-05-01',
            usedDays: 3.0,
        ));

        $this->bus()->dispatch(new ConfirmPaidLeaveUsage($user->id, $usageId, $user->id));

        $this->bus()->dispatch(new RevokePaidLeaveGrant($user->id, $grantId, $user->id, '誤付与のため取消'));

        $this->assertSame(0, PaidLeaveUsageAllocation::query()->where('grant_id', $grantId)->count());

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals('revoked', $grant->status);
        $this->assertEquals(0.0, (float) $grant->allocated_days);

        // Usage自体は取消されない(不変条件5)が、充当先を失い未充当のまま残る。
        $usage = PaidLeaveUsage::query()->where('usage_id', $usageId)->firstOrFail();
        $this->assertTrue($usage->confirmed);
        $this->assertFalse($usage->cancelled);

        $balance = PaidLeaveBalance::query()->findOrFail($user->id);
        $this->assertEquals(0.0, (float) $balance->available_days, '取消済みGrantはavailable_daysから除外される');
        $this->assertEquals(3.0, (float) $balance->unallocated_days, '充当先を失ったUsageはunallocated_daysに計上される');
    }
}
