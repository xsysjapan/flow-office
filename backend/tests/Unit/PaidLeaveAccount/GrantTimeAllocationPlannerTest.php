<?php

namespace Tests\Unit\PaidLeaveAccount;

use App\Domain\PaidLeaveAccount\Support\GrantTimeAllocationPlanner;
use PHPUnit\Framework\TestCase;

/**
 * 付与時の未充当の充当の業務ルール(論点19 決定10-1)。
 */
class GrantTimeAllocationPlannerTest extends TestCase
{
    public function test_allocates_in_confirm_order_within_the_grant_remaining(): void
    {
        $plan = (new GrantTimeAllocationPlanner)->plan(3.0, [
            ['usageId' => 'late', 'remainingDays' => 2.0, 'confirmOrder' => 5],
            ['usageId' => 'early', 'remainingDays' => 2.0, 'confirmOrder' => 1],
        ]);

        $this->assertSame([
            ['usageId' => 'early', 'allocatedDays' => 2.0],
            ['usageId' => 'late', 'allocatedDays' => 1.0],
        ], $plan);
    }

    public function test_usage_without_shortfall_is_skipped_and_exhausted_grant_allocates_nothing(): void
    {
        $planner = new GrantTimeAllocationPlanner;

        $this->assertSame([['usageId' => 'b', 'allocatedDays' => 1.0]], $planner->plan(5.0, [
            ['usageId' => 'a', 'remainingDays' => 0.0, 'confirmOrder' => 1],
            ['usageId' => 'b', 'remainingDays' => 1.0, 'confirmOrder' => 2],
        ]));
        $this->assertSame([], $planner->plan(0.0, [['usageId' => 'a', 'remainingDays' => 1.0, 'confirmOrder' => 1]]));
        $this->assertSame([], $planner->plan(5.0, []));
    }

    public function test_half_day_boundary_is_allocated_exactly(): void
    {
        $plan = (new GrantTimeAllocationPlanner)->plan(0.5, [
            ['usageId' => 'a', 'remainingDays' => 1.0, 'confirmOrder' => 1],
        ]);

        $this->assertSame([['usageId' => 'a', 'allocatedDays' => 0.5]], $plan);
    }
}
