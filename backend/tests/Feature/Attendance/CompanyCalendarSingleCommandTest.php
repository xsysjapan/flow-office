<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\Attendance\Commands\CreateCompanyCalendarYear;
use App\Domain\Attendance\Commands\RegenerateCompanyCalendarYear;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * 会社カレンダーの作成(年度つき)・年度の作り直しの入口が1つのCommandだけをdispatchし、
 * 途中で失敗すると何も残らない(docs/changesets/20261128-remaining-entrypoints/spec.md 論点2)。
 */
class CompanyCalendarSingleCommandTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_store_with_fiscal_year_is_a_single_top_level_command(): void
    {
        $admin = $this->makeAdmin();
        $bus = $this->recordCommands();

        $id = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-30',
        ])->assertCreated()->json('id');

        $this->assertSingleTopLevelCommand($bus, CreateCompanyCalendar::class);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $id)->firstOrFail();
        $this->assertSame(30, DB::table('company_calendar_days')->where('calendar_id', $year->id)->count());
    }

    public function test_store_without_fiscal_year_creates_only_the_calendar(): void
    {
        $admin = $this->makeAdmin();
        $bus = $this->recordCommands();

        $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '本社'])->assertCreated();

        $this->assertSingleTopLevelCommand($bus, CreateCompanyCalendar::class);
        $this->assertSame(0, CompanyCalendarYear::query()->count());
    }

    public function test_failure_of_the_year_step_leaves_no_calendar(): void
    {
        $admin = $this->makeAdmin();
        config(['domain.command_handlers.'.CreateCompanyCalendarYear::class => RejectingCalendarYearHandler::class]);
        $events = DB::table('stored_events')->count();

        $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-30',
        ])->assertUnprocessable();

        $this->assertSame(0, CompanyCalendar::query()->count());
        $this->assertSame($events, DB::table('stored_events')->count());
    }

    public function test_regenerate_is_a_single_top_level_command_and_rejects_published_year(): void
    {
        $admin = $this->makeAdmin();
        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-30',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->firstOrFail();

        $bus = $this->recordCommands();
        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/regenerate")->assertOk();
        $this->assertSingleTopLevelCommand($bus, RegenerateCompanyCalendarYear::class);

        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/publish")->assertOk();
        $events = DB::table('stored_events')->count();
        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/regenerate")->assertUnprocessable();
        $this->assertSame($events, DB::table('stored_events')->count());
    }
}

/** テスト用: 年度の作成を必ず拒否する。 */
class RejectingCalendarYearHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        throw new DomainRuleException('年度の作成を拒否します。');
    }
}
