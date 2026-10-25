<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\AccessControl\AccessControlCatalog;
use App\Domain\AccessControl\Commands\ChangeRolePermissions;
use App\Domain\EventSourcing\CommandBus;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 権限`read_model.rebuild`の付与(docs/changesets/20261025-admin-read-model-rebuild/spec.md 論点7)。
 * 新規環境はシーダー(AccessControlCatalog)、既存環境はマイグレーション(`ChangeRolePermissions`のCommandで`role.permissions_changed`を記録)。
 * 作り直し(`permission_role`を空にしてreplay)の後も管理者ロールが持っていることを確かめる。
 */
class ReadModelRebuildPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'read_model.rebuild';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_25_100000_add_read_model_rebuild_permission.php');
    }

    private function adminRoleId(): int
    {
        return (int) DB::table('roles')->where('code', 'admin')->value('id');
    }

    private function permissionId(): int
    {
        return (int) DB::table('permissions')->where('code', self::PERMISSION)->value('id');
    }

    private function adminHasPermission(): bool
    {
        return DB::table('permission_role')->where('role_id', $this->adminRoleId())->where('permission_id', $this->permissionId())->exists();
    }

    private function permissionChangedEvents(): int
    {
        return DB::table('stored_events')->where('event_class', 'role.permissions_changed')->count();
    }

    /** 既存環境の再現: 管理者ロールの権限から`read_model.rebuild`を外した状態(Command経由)。 */
    private function seedLegacyEnvironment(): void
    {
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        $without = DB::table('permission_role')->where('role_id', $this->adminRoleId())->where('permission_id', '!=', $this->permissionId())->pluck('permission_id')->map(fn ($id) => (int) $id)->all();
        app(CommandBus::class)->dispatch(new ChangeRolePermissions($this->adminRoleId(), $without, null));
        $this->assertFalse($this->adminHasPermission());
    }

    public function test_the_catalog_defines_the_global_permission_and_the_seeder_gives_it_to_the_admin_role(): void
    {
        $this->assertSame(['name' => 'データの作り直し', 'scopes' => ['global']], AccessControlCatalog::PERMISSIONS[self::PERMISSION]);

        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);

        $this->assertTrue($this->adminHasPermission());
        $this->assertSame(['global'], DB::table('permission_scope_types')->where('permission_id', $this->permissionId())->pluck('scope_type')->all());
        foreach (['employee', 'backoffice_staff', 'accounting_staff', 'general_affairs_staff', 'hr_staff'] as $code) {
            $this->assertFalse(DB::table('permission_role')->where('role_id', DB::table('roles')->where('code', $code)->value('id'))->where('permission_id', $this->permissionId())->exists(), $code);
        }
    }

    public function test_the_migration_adds_the_permission_row_and_gives_it_to_the_existing_admin_role_by_event(): void
    {
        $this->seedLegacyEnvironment();
        $events = $this->permissionChangedEvents();

        $this->migration()->up();

        $this->assertTrue($this->adminHasPermission());
        $this->assertSame($events + 1, $this->permissionChangedEvents());
        $this->assertSame(['global'], DB::table('permission_scope_types')->where('permission_id', $this->permissionId())->pluck('scope_type')->all());
        $row = DB::table('permissions')->where('code', self::PERMISSION)->first();
        $this->assertSame('read_model', $row->resource);
        $this->assertSame('rebuild', $row->action);
    }

    public function test_the_permission_survives_emptying_permission_role_and_replaying(): void
    {
        $this->seedLegacyEnvironment();
        $this->migration()->up();

        DB::table('permission_role')->delete();
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $this->assertTrue($this->adminHasPermission());
    }

    public function test_running_the_migration_again_does_nothing(): void
    {
        $this->seedLegacyEnvironment();
        $this->migration()->up();
        $events = $this->permissionChangedEvents();
        $count = DB::table('permission_role')->where('role_id', $this->adminRoleId())->count();

        $this->migration()->up();

        $this->assertSame($events, $this->permissionChangedEvents());
        $this->assertSame($count, DB::table('permission_role')->where('role_id', $this->adminRoleId())->count());
        $this->assertSame(1, DB::table('permissions')->where('code', self::PERMISSION)->count());
    }

    public function test_the_migration_does_nothing_for_the_role_when_there_is_no_admin_role_or_no_permissions_yet(): void
    {
        // 新規環境(ロールが無い)。permissionsの行だけ追加する。
        $this->migration()->up();
        $this->assertSame(0, $this->permissionChangedEvents());
        $this->assertSame(1, DB::table('permissions')->where('code', self::PERMISSION)->count());
    }
}
