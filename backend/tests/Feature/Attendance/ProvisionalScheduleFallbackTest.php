<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-C014 手順5: バッチ生成を待たない読み取りフォールバック(暫定計算)。
 */
class ProvisionalScheduleFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_provisional_entries_when_no_published_year_covers_the_range(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->getJson(
            "/api/employee-calendar-entries?user_id={$employee->id}&from=2026-07-01&to=2026-07-05"
        );

        $response->assertOk();
        $response->assertJsonPath('provisional', true);
        $days = $response->json('data');
        $this->assertCount(5, $days);
        $this->assertTrue($days[0]['provisional']);
        // 2026-07-01は水曜(勤務日)、2026-07-04は土曜(所定休日)。
        $this->assertTrue($days[0]['is_working_day']);
        $this->assertFalse($days[3]['is_working_day']);
    }

    public function test_does_not_return_provisional_entries_when_a_published_year_covers_the_range(): void
    {
        $employee = User::factory()->create();
        $calendar = CompanyCalendar::query()->create(['name' => '本社カレンダー', 'week_starts_on' => 1, 'is_default' => true]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        $response = $this->actingAs($employee)->getJson(
            "/api/employee-calendar-entries?user_id={$employee->id}&from=2026-07-01&to=2026-07-05"
        );

        $response->assertOk();
        $response->assertJsonPath('provisional', false);
        $this->assertCount(0, $response->json('data'));
    }
}
