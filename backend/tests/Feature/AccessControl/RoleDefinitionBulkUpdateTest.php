<?php

namespace Tests\Feature\AccessControl;

use App\Domain\AccessControl\Commands\ChangeRoleFeatures;
use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\EventSourcing\CommandBus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ロール詳細のまとめて保存 `PUT /admin/access-control/roles/{role}/definition`
 * (docs/changesets/20261125-save-all-user-and-role/spec.md 論点3・論点4)。
 */
class RoleDefinitionBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Role $role;

    private int $permissionAId;

    private int $permissionBId;

    private int $featureAId;

    private int $featureBId;

    private string $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->assignRole($this->admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->role = Role::query()->create(['code' => 'custom_role', 'name' => 'カスタム', 'description' => '説明', 'status' => 'active']);
        $now = ['created_at' => now(), 'updated_at' => now()];
        $this->permissionAId = DB::table('permissions')->insertGetId(['code' => 'rd_test.view', 'resource' => 'rd_test', 'action' => 'view'] + $now);
        $this->permissionBId = DB::table('permissions')->insertGetId(['code' => 'rd_test.edit', 'resource' => 'rd_test', 'action' => 'edit'] + $now);
        $this->featureAId = DB::table('features')->insertGetId(['code' => 'rd_test.a', 'name' => 'A', 'status' => 'active'] + $now);
        $this->featureBId = DB::table('features')->insertGetId(['code' => 'rd_test.b', 'name' => 'B', 'status' => 'active'] + $now);

        $groupTypeId = DB::table('group_types')->insertGetId(['code' => 'RD_TYPE', 'name' => 'test', 'status' => 'active'] + $now);
        $this->groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $this->groupId, 'group_type_id' => $groupTypeId, 'name' => 'グループ', 'code' => 'RD_GROUP', 'status' => 'active'] + $now);
    }

    private function url(?Role $role = null): string
    {
        return '/api/admin/access-control/roles/'.($role ?? $this->role)->id.'/definition';
    }

    private function roleEventCount(Role $role, string $eventClass): int
    {
        return DB::table('stored_events')->where('event_class', $eventClass)
            ->where('aggregate_uuid', \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'role:'.$role->code)->toString())->count();
    }

    private function roleEventTotal(): int
    {
        return DB::table('stored_events')->where('event_class', 'like', 'role.%')->count();
    }

    public function test_only_the_sent_items_are_dispatched_and_recorded(): void
    {
        $response = $this->actingAs($this->admin)->putJson($this->url(), [
            'name' => '改名',
            'permission_ids' => [$this->permissionAId],
        ]);

        $response->assertOk();
        $response->assertJsonPath('name', '改名');
        $response->assertJsonPath('description', '説明');
        $response->assertJsonPath('permissions.0.id', $this->permissionAId);
        $response->assertJsonPath('features', []);

        $this->assertSame(1, $this->roleEventCount($this->role, 'role.updated'));
        $this->assertSame(1, $this->roleEventCount($this->role, 'role.permissions_changed'));
        $this->assertSame(0, $this->roleEventCount($this->role, 'role.features_changed'));
        $this->assertSame('改名', $this->role->refresh()->name);
        $this->assertSame('説明', $this->role->description);
    }

    public function test_description_can_be_cleared_with_null(): void
    {
        $this->actingAs($this->admin)->putJson($this->url(), ['description' => null])->assertOk()->assertJsonPath('description', null);

        $this->assertNull($this->role->refresh()->description);
    }

    public function test_changing_features_syncs_the_groups_holding_the_role(): void
    {
        DB::table('permission_role')->insert(['role_id' => $this->role->id, 'permission_id' => $this->permissionAId]);
        DB::table('permission_scope_types')->insert(['permission_id' => $this->permissionAId, 'scope_type' => 'group']);
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->role->id, [$this->featureAId], $this->admin->id));
        $bus->dispatch(new CreateRoleAssignment(
            (string) Str::uuid(), 'group', $this->groupId, $this->role->id, 'group', $this->groupId, false, null, null, $this->admin->id
        ));
        $groupFeatures = fn () => DB::table('group_feature_assignments')->where('group_id', $this->groupId)->pluck('feature_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$this->featureAId], $groupFeatures());

        $this->actingAs($this->admin)->putJson($this->url(), ['feature_ids' => [$this->featureBId]])->assertOk();

        $this->assertSame([$this->featureBId], $groupFeatures());
        $this->assertSame(2, $this->roleEventCount($this->role, 'role.features_changed'));
    }

    public function test_deactivating_a_system_role_is_rejected_and_everything_is_rolled_back(): void
    {
        $system = Role::query()->create(['code' => 'sys_role', 'name' => 'システム', 'is_system' => true, 'status' => 'active']);
        $before = $this->roleEventTotal();

        $this->actingAs($this->admin)->putJson($this->url($system), [
            'status' => 'inactive',
            'permission_ids' => [$this->permissionAId],
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->assertSame('active', $system->refresh()->status);
        $this->assertSame($before, $this->roleEventTotal());
    }

    public function test_a_later_rejection_rolls_back_the_earlier_items(): void
    {
        $before = $this->roleEventTotal();

        $this->actingAs($this->admin)->putJson($this->url(), [
            'name' => '改名',
            'permission_ids' => [$this->permissionAId],
            'feature_ids' => [999999],
        ])->assertStatus(422)->assertJsonValidationErrors(['feature_ids']);

        $this->assertSame('カスタム', $this->role->refresh()->name);
        $this->assertSame([], DB::table('permission_role')->where('role_id', $this->role->id)->pluck('permission_id')->all());
        $this->assertSame($before, $this->roleEventTotal());
    }

    public function test_unknown_permission_is_rejected_with_the_permission_ids_key(): void
    {
        $this->actingAs($this->admin)->putJson($this->url(), ['permission_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors(['permission_ids']);
    }

    public function test_removing_user_manage_from_the_admin_role_is_rejected(): void
    {
        $adminRole = Role::query()->where('code', Role::ADMIN)->firstOrFail();
        $before = $this->roleEventTotal();

        $this->actingAs($this->admin)->putJson($this->url($adminRole), [
            'name' => '改名',
            'permission_ids' => [$this->permissionAId],
        ])->assertStatus(422)->assertJsonValidationErrors(['permission_ids']);

        $this->assertSame('管理者', $adminRole->refresh()->name);
        $this->assertSame($before, $this->roleEventTotal());
    }

    public function test_validation_errors_use_the_item_keys(): void
    {
        $this->actingAs($this->admin)->putJson($this->url(), [
            'name' => str_repeat('a', 300),
            'status' => 'archived',
            'permission_ids' => ['x'],
            'feature_ids' => 'y',
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'status', 'permission_ids.0', 'feature_ids']);
    }

    public function test_a_user_without_permission_gets_403(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->putJson($this->url(), ['name' => '改名'])->assertForbidden();

        $this->assertSame('カスタム', $this->role->refresh()->name);
    }
}
