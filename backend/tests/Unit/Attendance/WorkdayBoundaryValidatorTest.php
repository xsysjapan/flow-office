<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\Services\WorkdayBoundaryValidator;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class WorkdayBoundaryValidatorTest extends TestCase
{
    public function test_custom_boundary_accepts_one_workday_window(): void
    {
        $style = new WorkStyle(['workday_boundary_type' => 'custom', 'workday_boundary_time' => '05:00']);

        (new WorkdayBoundaryValidator)->assertWithinBoundary(
            $style,
            '2026-07-01',
            Carbon::parse('2026-07-01 22:00'),
            Carbon::parse('2026-07-02 04:00'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_midnight_boundary_rejects_crossing_into_next_calendar_day(): void
    {
        $this->expectException(DomainRuleException::class);
        $style = new WorkStyle(['workday_boundary_type' => 'midnight']);

        (new WorkdayBoundaryValidator)->assertWithinBoundary(
            $style,
            '2026-07-01',
            Carbon::parse('2026-07-01 22:00'),
            Carbon::parse('2026-07-02 01:00'),
        );
    }

    public function test_work_date_boundary_allows_crossing_midnight(): void
    {
        $style = new WorkStyle(['workday_boundary_type' => 'work_date']);

        (new WorkdayBoundaryValidator)->assertWithinBoundary(
            $style,
            '2026-07-01',
            Carbon::parse('2026-07-01 22:00'),
            Carbon::parse('2026-07-02 10:00'),
        );

        $this->addToAssertionCount(1);
    }
}
