<?php

namespace Tests\Feature\AccessControl;

use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `access-control:sync-catalog`は本番デプロイのたびに自動実行される(db:seedは実行しないため)。
 * AccessControlCatalogに追加したFeature・Permissionを反映しつつ、既存のRole・Feature割当を
 * 壊さないことを保証する。
 */
class SyncAccessControlCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
    }

    public function test_it_adds_a_permission_that_is_missing_from_the_database(): void
    {
        DB::table('permissions')->where('code', 'attendance.month_reopen')->delete();
        $this->assertDatabaseMissing('permissions', ['code' => 'attendance.month_reopen']);

        $this->artisan('access-control:sync-catalog')->assertSuccessful();

        $this->assertDatabaseHas('permissions', ['code' => 'attendance.month_reopen', 'resource' => 'attendance']);
    }

    public function test_it_does_not_change_existing_role_permission_assignments(): void
    {
        $roleId = DB::table('roles')->where('code', 'backoffice_staff')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'attendance.month_reopen')->value('id');
        DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);

        $this->artisan('access-control:sync-catalog')->assertSuccessful();

        $this->assertDatabaseHas('permission_role', ['role_id' => $roleId, 'permission_id' => $permissionId]);
    }

    public function test_it_does_not_change_existing_group_feature_assignments(): void
    {
        $groupId = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $featureId = DB::table('features')->where('code', 'administration')->value('id');
        DB::table('group_feature_assignments')->insert([
            'group_id' => $groupId,
            'feature_id' => $featureId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('access-control:sync-catalog')->assertSuccessful();

        $this->assertDatabaseHas('group_feature_assignments', ['group_id' => $groupId, 'feature_id' => $featureId]);
    }

    public function test_it_is_idempotent(): void
    {
        $this->artisan('access-control:sync-catalog')->assertSuccessful();
        $countAfterFirstRun = DB::table('permissions')->count();

        $this->artisan('access-control:sync-catalog')->assertSuccessful();

        $this->assertSame($countAfterFirstRun, DB::table('permissions')->count());
    }
}
