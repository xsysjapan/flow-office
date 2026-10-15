<?php

namespace Tests\Unit\CompensatoryLeaveAccount;

use App\Domain\CompensatoryLeaveAccount\Support\CompensatoryLeaveAllocationPlanner;
use Tests\TestCase;

/**
 * CompensatoryLeaveAllocationPlannerの単体テスト(純粋関数。Eloquent・Projectionは使わない)。
 * 移植元の現行ルール: ApproveCompensatoryLeaveRequestHandler::planConsumption。
 */
class CompensatoryLeaveAllocationPlannerTest extends TestCase
{
    private CompensatoryLeaveAllocationPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = new CompensatoryLeaveAllocationPlanner;
    }

    /**
     * @return array{grantId: string, expiresOn: ?string, available: float}
     */
    private function grant(string $id, ?string $expiresOn, float $available): array
    {
        return ['grantId' => $id, 'expiresOn' => $expiresOn, 'available' => $available];
    }

    public function test_no_grants_yields_empty_plan(): void
    {
        $this->assertSame([], $this->planner->plan('2026-10-10', 1.0, []));
    }

    public function test_zero_required_yields_empty_plan(): void
    {
        $this->assertSame([], $this->planner->plan('2026-10-10', 0.0, [$this->grant('g1', null, 1.0)]));
    }

    public function test_nearest_expiry_is_used_first(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g-late', '2027-06-30', 3.0),
            $this->grant('g-early', '2026-12-31', 3.0),
        ]);

        $this->assertSame([['grantId' => 'g-early', 'allocatedAmount' => 1.0]], $plan);
    }

    public function test_grant_without_expiry_is_used_after_expiring_grants(): void
    {
        $plan = $this->planner->plan('2026-10-10', 2.0, [
            $this->grant('g-none', null, 5.0),
            $this->grant('g-dated', '2027-03-31', 1.0),
        ]);

        $this->assertSame([
            ['grantId' => 'g-dated', 'allocatedAmount' => 1.0],
            ['grantId' => 'g-none', 'allocatedAmount' => 1.0],
        ], $plan);
    }

    public function test_grants_with_same_expiry_are_used_in_registration_order(): void
    {
        $plan = $this->planner->plan('2026-10-10', 2.0, [
            $this->grant('g-first', '2026-12-31', 1.0),
            $this->grant('g-second', '2026-12-31', 3.0),
        ]);

        $this->assertSame([
            ['grantId' => 'g-first', 'allocatedAmount' => 1.0],
            ['grantId' => 'g-second', 'allocatedAmount' => 1.0],
        ], $plan);
    }

    public function test_expired_grant_is_not_used(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g-old', '2026-10-09', 3.0),
            $this->grant('g-ok', '2026-12-31', 3.0),
        ]);

        $this->assertSame([['grantId' => 'g-ok', 'allocatedAmount' => 1.0]], $plan);
    }

    public function test_grant_expiring_on_usage_day_is_still_used(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [$this->grant('g-edge', '2026-10-10', 1.0)]);

        $this->assertSame([['grantId' => 'g-edge', 'allocatedAmount' => 1.0]], $plan);
    }

    public function test_exhausted_grant_is_not_used(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g-empty', '2026-09-01', 0.0),
            $this->grant('g-ok', '2026-12-31', 1.0),
        ]);

        $this->assertSame([['grantId' => 'g-ok', 'allocatedAmount' => 1.0]], $plan);
    }

    public function test_requirement_is_split_across_grants(): void
    {
        $plan = $this->planner->plan('2026-10-10', 1.0, [
            $this->grant('g1', '2026-12-31', 0.5),
            $this->grant('g2', '2027-03-31', 1.0),
        ]);

        $this->assertSame([
            ['grantId' => 'g1', 'allocatedAmount' => 0.5],
            ['grantId' => 'g2', 'allocatedAmount' => 0.5],
        ], $plan);
    }

    public function test_shortage_is_left_unplanned(): void
    {
        // 不足分は計画に含めない(判定は呼び出し側の集約が行う)。
        $plan = $this->planner->plan('2026-10-10', 1.5, [$this->grant('g1', null, 1.0)]);

        $this->assertSame([['grantId' => 'g1', 'allocatedAmount' => 1.0]], $plan);
        $this->assertSame(1.0, array_sum(array_column($plan, 'allocatedAmount')));
    }
}
