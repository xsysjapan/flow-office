<?php

namespace Tests\Feature\Attendance;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 指示書 3.1節・3.2節: 会社のデフォルト働き方は明示的に管理し、常に高々1件のみとする。
 */
class WorkStyleDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_the_default_work_style_is_created_with_standard_values(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/work-styles/default', []);

        $response->assertCreated()
            ->assertJsonPath('name', '通常勤務')
            ->assertJsonPath('work_time_system', 'fixed')
            ->assertJsonPath('prescribed_daily_minutes', 480)
            ->assertJsonPath('default_start_time', '09:00')
            ->assertJsonPath('default_end_time', '18:00')
            ->assertJsonPath('is_default', true)
            ->assertJsonPath('system_generated', true);

        $this->assertSame(
            $response->json('id'),
            SystemSetting::current()->default_work_style_id,
        );
    }

    public function test_the_default_work_style_can_override_standard_values(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/work-styles/default', [
            'name' => '標準勤務(カスタム)',
            'default_start_time' => '08:30',
            'default_end_time' => '17:30',
            'default_break_minutes' => 45,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', '標準勤務(カスタム)')
            ->assertJsonPath('default_start_time', '08:30')
            ->assertJsonPath('default_end_time', '17:30')
            ->assertJsonPath('default_break_minutes', 45)
            ->assertJsonPath('system_generated', true);
    }

    public function test_creating_a_default_work_style_fails_when_one_already_exists(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->postJson('/api/work-styles/default', [])->assertCreated();

        $response = $this->actingAs($admin)->postJson('/api/work-styles/default', []);

        $response->assertStatus(422);
        $this->assertSame(1, WorkStyle::query()->where('is_default', true)->count());
    }

    public function test_switching_the_default_unsets_the_previous_one_and_syncs_system_settings(): void
    {
        $admin = $this->makeAdmin();
        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);

        $original = $this->actingAs($admin)->postJson('/api/work-styles/default', [])->json();

        $flex = $this->actingAs($admin)->postJson('/api/work-styles', [
            'code' => 'flex-standard',
            'name' => 'フレックス標準',
            'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
        ])->json();

        $response = $this->actingAs($admin)->postJson("/api/work-styles/{$flex['id']}/set-default");

        $response->assertOk()->assertJsonPath('is_default', true);
        $this->assertFalse(WorkStyle::query()->find($original['id'])->is_default);
        $this->assertTrue(WorkStyle::query()->find($flex['id'])->is_default);
        $this->assertSame($flex['id'], SystemSetting::current()->default_work_style_id);
    }
}
