<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/16-database-schema.md: company_calendars.is_defaultは組織内に常に高々1件のみtrue。
 * `ProvisionalScheduleCalculator`(UC-C014手順5)のフォールバック判定がこれに依存する。
 */
class CompanyCalendarDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_the_first_company_calendar_created_becomes_the_default_automatically(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('is_default', true);

        $calendarId = $response->json('id');
        $this->assertDatabaseHas('company_calendars', ['id' => $calendarId, 'is_default' => true]);
        $this->assertDatabaseHas('stored_events', ['event_class' => 'company_calendar.default_changed']);
    }

    public function test_a_second_company_calendar_created_does_not_become_the_default(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '本社カレンダー'])->assertCreated();

        $second = $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '名古屋事業所カレンダー']);
        $second->assertCreated();
        $second->assertJsonPath('is_default', false);

        $this->assertDatabaseHas('company_calendars', ['id' => $second->json('id'), 'is_default' => false]);
    }

    public function test_set_default_switches_the_default_and_unsets_the_previous_one(): void
    {
        $admin = $this->makeAdmin();

        $first = $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '本社カレンダー'])->json();
        $second = $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '名古屋事業所カレンダー'])->json();

        $this->assertTrue(CompanyCalendar::query()->findOrFail($first['id'])->is_default);
        $this->assertFalse(CompanyCalendar::query()->findOrFail($second['id'])->is_default);

        $response = $this->actingAs($admin)->postJson("/api/company-calendars/{$second['id']}/set-default");

        $response->assertOk();
        $response->assertJsonPath('is_default', true);

        $this->assertFalse(CompanyCalendar::query()->findOrFail($first['id'])->is_default);
        $this->assertTrue(CompanyCalendar::query()->findOrFail($second['id'])->is_default);
    }

    public function test_set_default_requires_attendance_manage_permission(): void
    {
        $admin = $this->makeAdmin();
        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '本社カレンダー'])->json('id');

        $plainUser = User::factory()->create();

        $response = $this->actingAs($plainUser)->postJson("/api/company-calendars/{$calendarId}/set-default");

        $response->assertForbidden();
    }

    public function test_a_company_calendar_can_be_created_with_only_a_name_and_edited_later(): void
    {
        $admin = $this->makeAdmin();

        $created = $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '本社カレンダー'])->json();
        $this->assertSame(1, $created['week_starts_on']);
        $this->assertSame(4, $created['fiscal_year_start_month']);
        $this->assertSame(1, $created['fiscal_year_start_day']);

        $updated = $this->actingAs($admin)->putJson("/api/company-calendars/{$created['id']}", [
            'name' => '本社カレンダー(改称)',
            'week_starts_on' => 7,
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
        ]);

        $updated->assertOk();
        $updated->assertJsonPath('name', '本社カレンダー(改称)');
        $updated->assertJsonPath('week_starts_on', 7);
        $updated->assertJsonPath('fiscal_year_start_month', 1);
        $updated->assertJsonPath('fiscal_year_start_day', 1);

        $this->assertDatabaseHas('company_calendars', [
            'id' => $created['id'],
            'name' => '本社カレンダー(改称)',
            'week_starts_on' => 7,
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
        ]);
    }

    public function test_update_requires_attendance_manage_permission(): void
    {
        $admin = $this->makeAdmin();
        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '本社カレンダー'])->json('id');

        $plainUser = User::factory()->create();

        $response = $this->actingAs($plainUser)->putJson("/api/company-calendars/{$calendarId}", ['name' => '改称後']);

        $response->assertForbidden();
    }
}
