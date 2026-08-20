<?php

namespace Tests\Feature;

use App\Jobs\RunAdminCommandJob;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
            ['attendance:migrate-work-classifications', 'attendance:normalize-calculation-events', 'attendance:rebuild-calculation-projections', 'attendance:recalculate-month-snapshots'],
            $commands->keys()->sort()->values()->all(),
        );
        $this->assertSame('apply', $commands['attendance:migrate-work-classifications']['parameters'][0]['name']);
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

    public function test_attendance_work_classification_migration_defaults_to_dry_run(): void
    {
        $this->artisan('attendance:migrate-work-classifications --year-month=2026-07')
            ->expectsOutputToContain('事前確認を実行します。データは変更しません。')
            ->expectsOutputToContain('ドライランです。--applyでバックアップ後に変換します。')
            ->expectsOutputToContain('0 件が対象です(--dry-runのため未計算)。')
            ->assertSuccessful();
    }

    public function test_attendance_work_classification_migration_applies_all_steps(): void
    {
        $backupTable = 'stored_events_backup_work_classification_test';

        $this->artisan("attendance:migrate-work-classifications --apply --backup-table={$backupTable} --year-month=2026-07")
            ->expectsOutputToContain('勤怠計算Projectionの再構築が完了しました。')
            ->expectsOutputToContain('勤怠5区分データ移行が完了しました。')
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable($backupTable));
    }
}
