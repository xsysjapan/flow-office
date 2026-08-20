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

        $commands = collect($response->json('data'))->keyBy('name');
        $this->assertSame(
            ['attendance:normalize-calculation-events', 'attendance:rebuild-calculation-projections', 'attendance:recalculate-month-snapshots'],
            $commands->keys()->sort()->values()->all(),
        );
        $this->assertSame('apply', $commands['attendance:normalize-calculation-events']['parameters'][0]['name']);
        $this->assertSame('year-month', $commands['attendance:recalculate-month-snapshots']['parameters'][0]['name']);
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

        $this->actingAs($admin)->postJson('/api/admin/commands/attendance:normalize-calculation-events/runs', [
            'parameters' => ['apply' => true, 'backup-table' => 'invalid-table-name'],
        ])->assertUnprocessable();
    }

    public function test_attendance_calculation_projection_rebuild_command_completes(): void
    {
        $this->artisan('attendance:rebuild-calculation-projections')
            ->expectsOutputToContain('勤怠計算Projectionの再構築が完了しました。')
            ->assertSuccessful();
    }
}
