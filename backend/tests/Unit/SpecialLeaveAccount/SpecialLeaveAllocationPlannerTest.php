<?php

namespace Tests\Unit\SpecialLeaveAccount;

use App\Domain\SpecialLeaveAccount\Support\SpecialLeaveAllocationPlanner;
use Tests\TestCase;

/**
 * SpecialLeaveAllocationPlannerの単体テスト(純粋関数。Eloquent・Projectionは使わない)。
 * 移植元の現行ルール: ApproveSpecialLeaveRequestHandler::planConsumption。
 */
class SpecialLeaveAllocationPlannerTest extends TestCase
{
    private SpecialLeaveAllocationPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = new SpecialLeaveAllocationPlanner;
    }

    /**
     * @return array{grantId: string, expiresOn: ?string, grantedDays: float, allocatedTotal: float, revoked: bool}
     */
    private function grant(string $id, ?string $expiresOn, float $grantedDays, float $allocatedTotal = 0.0, bool $revoked = false): array
    {
        return [
            'grantId' => $id,
            'expiresOn' => $expiresOn,
            'grantedDays' => $grantedDays,
            'allocatedTotal' => $allocatedTotal,
            'revoked' => $revoked,
        ];
    }

    public function test_no_grants_yields_empty_plan(): void
    {
        $this->assertSame([], $this->planner->plan('2026-10-10', 1.0, []));
    }

    public function test_zero_usage_yields_empty_plan(): void
    {
        $this->assertSame([], $this->planner->plan('2026-10-10', 0.0, [$this->grant('g1', null, 1.0)]));
    }

    public function test_nearest_expiry_is_used_first(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g-late', '2027-06-30', 3.0),
            $this->grant('g-early', '2026-12-31', 3.0),
        ]);

        $this->assertSame([['grantId' => 'g-early', 'allocatedDays' => 1.0]], $plan);
    }

    public function test_grant_without_expiry_is_used_after_expiring_grants(): void
    {
        $plan = $this->planner->plan('2026-10-10', 2.0, [
            $this->grant('g-none', null, 5.0),
            $this->grant('g-2027', '2027-06-30', 1.0),
        ]);

        $this->assertSame([
            ['grantId' => 'g-2027', 'allocatedDays' => 1.0],
            ['grantId' => 'g-none', 'allocatedDays' => 1.0],
        ], $plan);
    }

    public function test_same_expiry_keeps_input_order(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.5, [
            $this->grant('g-a', '2026-12-31', 1.0),
            $this->grant('g-b', '2026-12-31', 1.0),
        ]);

        $this->assertSame([
            ['grantId' => 'g-a', 'allocatedDays' => 1.0],
            ['grantId' => 'g-b', 'allocatedDays' => 0.5],
        ], $plan);
    }

    public function test_allocation_spans_multiple_grants(): void
    {
        $plan = $this->planner->plan('2026-10-10', 2.5, [
            $this->grant('g1', '2026-12-31', 1.0),
            $this->grant('g2', '2027-06-30', 3.0),
        ]);

        $this->assertSame([
            ['grantId' => 'g1', 'allocatedDays' => 1.0],
            ['grantId' => 'g2', 'allocatedDays' => 1.5],
        ], $plan);
    }

    public function test_exactly_remaining_balance_is_fully_allocated(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g1', '2026-12-31', 2.0, 1.0),
        ]);

        $this->assertSame([['grantId' => 'g1', 'allocatedDays' => 1.0]], $plan);
    }

    public function test_already_allocated_amount_is_excluded_from_available_days(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.5, [
            $this->grant('g1', '2026-12-31', 2.0, 1.5),
            $this->grant('g2', '2027-12-31', 2.0, 0.0),
        ]);

        // g1は残数0.5(失効が近いため先に使う)、残りをg2から充当する。
        $this->assertSame([
            ['grantId' => 'g1', 'allocatedDays' => 0.5],
            ['grantId' => 'g2', 'allocatedDays' => 1.0],
        ], $plan);
    }

    public function test_fully_consumed_grant_is_skipped(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g1', '2026-12-31', 1.0, 1.0),
            $this->grant('g2', '2027-12-31', 1.0),
        ]);

        $this->assertSame([['grantId' => 'g2', 'allocatedDays' => 1.0]], $plan);
    }

    public function test_expired_grant_is_not_used(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g-expired', '2026-10-09', 1.0),
            $this->grant('g-valid', '2026-12-31', 1.0),
        ]);

        $this->assertSame([['grantId' => 'g-valid', 'allocatedDays' => 1.0]], $plan);
    }

    public function test_grant_expiring_on_usage_day_is_used(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g1', '2026-10-10', 1.0),
        ]);

        $this->assertSame([['grantId' => 'g1', 'allocatedDays' => 1.0]], $plan);
    }

    public function test_revoked_grant_is_not_used(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g-revoked', '2026-12-31', 1.0, 0.0, true),
            $this->grant('g-valid', '2027-12-31', 1.0),
        ]);

        $this->assertSame([['grantId' => 'g-valid', 'allocatedDays' => 1.0]], $plan);
    }

    public function test_shortage_is_left_unallocated_in_the_plan(): void
    {
        $plan = $this->planner->plan('2026-10-10', 2.0, [
            $this->grant('g1', '2026-12-31', 1.0),
        ]);

        // 不足分は計画に含めない(判定は呼び出し側の集約が行う)。
        $this->assertSame([['grantId' => 'g1', 'allocatedDays' => 1.0]], $plan);
        $this->assertSame(1.0, array_sum(array_column($plan, 'allocatedDays')));
    }
}
