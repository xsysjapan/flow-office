<?php

namespace Tests\Feature;

use App\Jobs\RunAdminCommandJob;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_explicitly_exposed_commands_are_listed_with_artisan_metadata(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => 'Admin']));

        $response = $this->actingAs($admin)->getJson('/api/admin/commands')->assertOk();

        $response->assertJsonPath('data.0.name', 'attendance:recalculate-month-snapshots')
            ->assertJsonPath('data.0.parameters.0.name', 'year-month');
        $this->assertNotContains('migrate:fresh', $response->json('data.*.name'));
    }

    public function test_validated_command_run_is_queued_and_unknown_parameters_are_rejected(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => 'Admin']));

        $this->actingAs($admin)->postJson('/api/admin/commands/attendance:recalculate-month-snapshots/runs', [
            'parameters' => ['year-month' => '2026-07', 'dry-run' => true],
        ])->assertAccepted()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(RunAdminCommandJob::class);

        $this->actingAs($admin)->postJson('/api/admin/commands/attendance:recalculate-month-snapshots/runs', [
            'parameters' => ['unexpected' => 'value'],
        ])->assertUnprocessable();
    }
}
