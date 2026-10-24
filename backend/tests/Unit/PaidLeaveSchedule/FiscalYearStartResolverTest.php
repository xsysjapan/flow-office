<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\FiscalYearStartResolver;
use App\Models\PaidLeaveFiscalYearSetting;
use App\Support\FiscalYearCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FiscalYearStartResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_april_first_when_there_is_no_row(): void
    {
        PaidLeaveFiscalYearSetting::query()->delete();

        $this->assertSame(['month' => 4, 'day' => 1], (new FiscalYearStartResolver)->resolve());
    }

    public function test_it_reads_the_view_row(): void
    {
        PaidLeaveFiscalYearSetting::query()->updateOrCreate(
            ['id' => 1],
            ['fiscal_year_start_month' => 10, 'fiscal_year_start_day' => 16],
        );

        $this->assertSame(['month' => 10, 'day' => 16], (new FiscalYearStartResolver)->resolve());
    }

    public function test_next_start_after_is_strictly_after(): void
    {
        $this->assertSame('2027-04-01', FiscalYearCalculator::nextStartAfter(Carbon::parse('2026-04-01'), 4, 1)->toDateString());
        $this->assertSame('2026-04-01', FiscalYearCalculator::nextStartAfter(Carbon::parse('2026-03-31'), 4, 1)->toDateString());
        $this->assertSame('2026-10-01', FiscalYearCalculator::nextStartAfter(Carbon::parse('2026-04-01'), 10, 1)->toDateString());
        $this->assertSame('2026-04-16', FiscalYearCalculator::nextStartAfter(Carbon::parse('2026-01-01'), 4, 16)->toDateString());
        $this->assertSame('2027-04-16', FiscalYearCalculator::nextStartAfter(Carbon::parse('2026-04-16'), 4, 16)->toDateString());
    }

    public function test_fiscal_year_for_matches_the_calendar_year_generation_rule(): void
    {
        $fiscalYear = FiscalYearCalculator::fiscalYearFor(Carbon::parse('2026-02-10'), 4, 1);

        $this->assertSame(2025, $fiscalYear['fiscal_year']);
        $this->assertSame('2025-04-01', $fiscalYear['starts_on']->toDateString());
        $this->assertSame('2026-03-31', $fiscalYear['ends_on']->toDateString());
    }
}
