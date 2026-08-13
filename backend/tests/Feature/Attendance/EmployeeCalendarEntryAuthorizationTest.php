<?php

namespace Tests\Feature\Attendance;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 勤務予定の閲覧は本人または管理者に限定する(docs/25-usecases-integrations-mcp.md UC-I002、
 * schedule:self:read スコープの前提となる自己スコープ制限)。
 */
class EmployeeCalendarEntryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_cannot_view_another_employees_calendar_entrys(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->getJson(
            "/api/employee-calendar-entries?user_id={$other->id}&from=2026-07-01&to=2026-07-31"
        )->assertForbidden();
    }

    public function test_an_admin_can_view_another_employees_calendar_entrys(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $other = User::factory()->create();

        $this->actingAs($admin)->getJson(
            "/api/employee-calendar-entries?user_id={$other->id}&from=2026-07-01&to=2026-07-31"
        )->assertSuccessful();
    }
}
