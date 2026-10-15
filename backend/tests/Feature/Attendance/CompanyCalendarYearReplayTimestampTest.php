<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendarYear;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 論点19 決定2: 暦の年のProjectorは、再生で同じ結果になるようイベントの発生日時で書く。
 */
class CompanyCalendarYearReplayTimestampTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_writes_published_at_from_the_event_time(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->firstOrFail();
        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/publish")->assertOk();

        // Spatieの createdAt() は meta_data の 'created-at' から読むため、created_at 列と合わせて両方を書き換える。
        $publishedEvents = DB::table('stored_events')->where('event_class', 'company_calendar_year.published')->get();
        foreach ($publishedEvents as $publishedEvent) {
            $meta = json_decode($publishedEvent->meta_data, true);
            $meta['created-at'] = '2026-03-01 09:00:00';
            DB::table('stored_events')->where('id', $publishedEvent->id)->update([
                'created_at' => '2026-03-01 09:00:00',
                'meta_data' => json_encode($meta),
            ]);
        }
        DB::table('company_calendar_years')->delete();

        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $replayed = CompanyCalendarYear::query()->findOrFail($year->id);
        $this->assertSame('published', $replayed->status);
        $this->assertSame('2026-03-01 09:00:00', $replayed->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-01 09:00:00', $replayed->updated_at->utc()->format('Y-m-d H:i:s'));
    }
}
