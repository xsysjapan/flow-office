<?php

namespace Tests\Feature;

use App\Models\AdminCommandRun;
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
            ['access-control:resync-group-features', 'attendance:rebuild-calculation-projections', 'attendance:recalculate-days', 'attendance:recalculate-month-snapshots', 'events:fix-calendar-bulk-operation-target-ids', 'events:fix-rebuild-determinism', 'events:fix-user-settings-at-replay', 'events:rename-boolean-properties', 'paid-leave:schedule:reapply-policy', 'paid-leave:schedule:rebuild', 'read-models:rebuild'],
            $commands->keys()->sort()->values()->all(),
        );
        $this->assertSame('year-month', $commands['attendance:recalculate-month-snapshots']['parameters'][0]['name']);
        $this->assertContains('from', collect($commands['attendance:recalculate-days']['parameters'])->pluck('name')->all());
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
        Queue::assertNothingPushed();
        $run = AdminCommandRun::query()->sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame('attendance:recalculate-month-snapshots', $run->command_name);

        $this->actingAs($admin)->postJson('/api/admin/commands/attendance:recalculate-month-snapshots/runs', [
            'parameters' => ['unexpected' => 'value'],
        ])->assertUnprocessable();
    }

    public function test_attendance_calculation_projection_rebuild_command_completes(): void
    {
        $this->artisan('attendance:rebuild-calculation-projections')
            ->expectsOutputToContain('勤怠計算Projectionの再構築が完了しました。')
            ->assertSuccessful();
    }
}
