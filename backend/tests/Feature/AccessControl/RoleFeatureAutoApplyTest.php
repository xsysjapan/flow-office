<?php

namespace Tests\Feature\AccessControl;

use App\Domain\AccessControl\Commands\ChangeRoleFeatures;
use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveRoleAssignment;
use App\Domain\AccessControl\Services\GroupFeatureSyncService;
use App\Domain\EventSourcing\CommandBus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleFeatureAutoApplyTest extends TestCase
{
    use RefreshDatabase;

    private string $groupId;

    private int $roleId;

    private int $featureAId;

    private int $featureBId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();

        $groupTypeId = DB::table('group_types')->insertGetId([
            'code' => 'RF_TEST_TYPE', 'name' => 'test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->groupId = (string) Str::uuid();
        DB::table('groups')->insert([
            'id' => $this->groupId, 'group_type_id' => $groupTypeId, 'name' => 'テストグループ', 'code' => 'RF_TEST_GROUP',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $role = Role::query()->create(['code' => 'rf_test_role', 'name' => 'RFテストRole', 'status' => 'active']);
        $this->roleId = $role->id;

        $permissionId = DB::table('permissions')->insertGetId([
            'code' => 'rf_test.view', 'resource' => 'rf_test', 'action' => 'view', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permission_role')->insert(['role_id' => $this->roleId, 'permission_id' => $permissionId]);
        DB::table('permission_scope_types')->insert(['permission_id' => $permissionId, 'scope_type' => 'group']);

        $this->featureAId = DB::table('features')->insertGetId([
            'code' => 'rf_test.feature_a', 'name' => 'Feature A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->featureBId = DB::table('features')->insertGetId([
            'code' => 'rf_test.feature_b', 'name' => 'Feature B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function groupFeatureIds(): array
    {
        return DB::table('group_feature_assignments')
            ->where('group_id', $this->groupId)
            ->pluck('feature_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    public function test_change_role_features_updates_role_features_table(): void
    {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId, $this->featureBId], $this->admin->id));

        $stored = DB::table('role_features')->where('role_id', $this->roleId)->pluck('feature_id')->sort()->values()->all();
        $this->assertSame([$this->featureAId, $this->featureBId], $stored);

        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId], $this->admin->id));
        $stored = DB::table('role_features')->where('role_id', $this->roleId)->pluck('feature_id')->all();
        $this->assertSame([$this->featureAId], $stored);
    }

    public function test_assigning_role_to_group_auto_syncs_features(): void
    {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId, $this->featureBId], $this->admin->id));

        $assignmentId = (string) Str::uuid();
        $bus->dispatch(new CreateRoleAssignment(
            $assignmentId, 'group', $this->groupId, $this->roleId, 'group', $this->groupId, false, null, null, $this->admin->id
        ));

        $this->assertSame([$this->featureAId, $this->featureBId], $this->groupFeatureIds());

        $bus->dispatch(new RemoveRoleAssignment($assignmentId, $this->admin->id));

        $this->assertSame([], $this->groupFeatureIds());
    }

    public function test_changing_role_features_resyncs_all_groups_holding_that_role(): void
    {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId], $this->admin->id));

        $assignmentId = (string) Str::uuid();
        $bus->dispatch(new CreateRoleAssignment(
            $assignmentId, 'group', $this->groupId, $this->roleId, 'group', $this->groupId, false, null, null, $this->admin->id
        ));
        $this->assertSame([$this->featureAId], $this->groupFeatureIds());

        // Change the role's features: featureA removed, featureB added -> group should follow.
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureBId], $this->admin->id));

        $this->assertSame([$this->featureBId], $this->groupFeatureIds());
    }

    public function test_group_feature_sync_service_only_touches_the_diff(): void
    {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId], $this->admin->id));

        $assignmentId = (string) Str::uuid();
        $bus->dispatch(new CreateRoleAssignment(
            $assignmentId, 'group', $this->groupId, $this->roleId, 'group', $this->groupId, false, null, null, $this->admin->id
        ));

        $existing = DB::table('group_feature_assignments')->where('group_id', $this->groupId)->where('feature_id', $this->featureAId)->first();
        $this->assertNotNull($existing);

        // Re-run sync directly; the existing row should not be recreated (assigned relation stays untouched).
        app(GroupFeatureSyncService::class)->syncGroup($this->groupId, $this->admin->id);

        $afterSync = DB::table('group_feature_assignments')->where('group_id', $this->groupId)->where('feature_id', $this->featureAId)->first();
        $this->assertEquals($existing->created_at, $afterSync->created_at);
        $this->assertSame([$this->featureAId], $this->groupFeatureIds());
    }
}
