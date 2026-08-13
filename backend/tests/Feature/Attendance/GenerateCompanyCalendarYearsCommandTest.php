<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * UC-C014: カレンダー年度を定期バッチで生成する。
 */
class GenerateCompanyCalendarYearsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_creates_current_and_next_fiscal_year_when_none_exist(): void
    {
        Carbon::setTestNow('2026-06-15');

        $calendar = CompanyCalendar::query()->create([
            'name' => '本社カレンダー', 'week_starts_on' => 1,
            'fiscal_year_start_month' => 4, 'fiscal_year_start_day' => 1,
        ]);

        Artisan::call('calendar:generate-years');

        $years = CompanyCalendarYear::query()->where('company_calendar_id', $calendar->id)->orderBy('fiscal_year')->get();
        $this->assertCount(2, $years);
        $this->assertSame(2026, $years[0]->fiscal_year);
        $this->assertSame('2026-04-01', $years[0]->starts_on->toDateString());
        $this->assertSame('2027-03-31', $years[0]->ends_on->toDateString());
        $this->assertSame('draft', $years[0]->status);
        $this->assertSame(2027, $years[1]->fiscal_year);

        // 平日は勤務日・週末は所定休日として生成されている。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $years[0]->id, 'date' => '2026-04-01 00:00:00', 'schedule_state' => 'WORK',
        ]);
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $years[0]->id, 'date' => '2026-04-04 00:00:00', 'schedule_state' => 'OFF', // 4/4は土曜
        ]);

        // 土曜は所定休日(法定休日ではない)、日曜は所定休日かつ法定休日(指示書6.1節)。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $years[0]->id, 'date' => '2026-04-04 00:00:00', 'is_legal_holiday' => false, // 4/4は土曜
        ]);
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $years[0]->id, 'date' => '2026-04-05 00:00:00', 'schedule_state' => 'OFF', 'is_legal_holiday' => true, // 4/5は日曜
        ]);

        Carbon::setTestNow();
    }

    public function test_batch_is_idempotent(): void
    {
        Carbon::setTestNow('2026-06-15');

        $calendar = CompanyCalendar::query()->create([
            'name' => '本社カレンダー', 'week_starts_on' => 1,
        ]);

        Artisan::call('calendar:generate-years');
        $firstRunCount = CompanyCalendarYear::query()->where('company_calendar_id', $calendar->id)->count();

        Artisan::call('calendar:generate-years');
        $secondRunCount = CompanyCalendarYear::query()->where('company_calendar_id', $calendar->id)->count();

        $this->assertSame($firstRunCount, $secondRunCount);

        Carbon::setTestNow();
    }

    public function test_batch_generates_the_next_fiscal_year_within_six_months_of_the_latest_years_end(): void
    {
        Carbon::setTestNow('2026-10-01');

        $calendar = CompanyCalendar::query()->create([
            'name' => '本社カレンダー', 'week_starts_on' => 1,
        ]);
        $calendar->years()->create([
            'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published',
        ]);

        // 2027-03-31まで残り6か月未満(2026-10-01時点)なので、次年度が生成される。
        Artisan::call('calendar:generate-years');

        $this->assertDatabaseHas('company_calendar_years', [
            'company_calendar_id' => $calendar->id, 'fiscal_year' => 2027, 'status' => 'draft',
        ]);

        Carbon::setTestNow();
    }

    public function test_batch_does_not_generate_the_next_fiscal_year_when_more_than_six_months_remain(): void
    {
        Carbon::setTestNow('2026-06-01');

        $calendar = CompanyCalendar::query()->create([
            'name' => '本社カレンダー', 'week_starts_on' => 1,
        ]);
        $calendar->years()->create([
            'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published',
        ]);

        Artisan::call('calendar:generate-years');

        $this->assertDatabaseMissing('company_calendar_years', [
            'company_calendar_id' => $calendar->id, 'fiscal_year' => 2027,
        ]);

        Carbon::setTestNow();
    }
}
