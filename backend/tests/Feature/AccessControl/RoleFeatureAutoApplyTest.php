<?php

namespace Tests\Feature\AccessControl;

use App\Domain\AccessControl\Commands\ChangeRoleFeatures;
use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveRoleAssignment;
use App\Domain\AccessControl\Commands\SyncGroupFeatures;
use App\Domain\AccessControl\Commands\UpdateRoleAssignment;
use App\Domain\EventSourcing\CommandBus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
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

        // Re-run the sync command; no diff means nothing is recorded and the existing row stays untouched.
        $eventsBefore = EloquentStoredEvent::query()->count();
        $bus->dispatch(new SyncGroupFeatures($this->groupId, $this->admin->id));
        $this->assertSame($eventsBefore, EloquentStoredEvent::query()->count());

        $afterSync = DB::table('group_feature_assignments')->where('group_id', $this->groupId)->where('feature_id', $this->featureAId)->first();
        $this->assertEquals($existing->created_at, $afterSync->created_at);
        $this->assertSame([$this->featureAId], $this->groupFeatureIds());
    }

    public function test_sync_records_the_whole_diff_on_the_group_access_aggregate(): void
    {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId], $this->admin->id));
        $bus->dispatch(new CreateRoleAssignment(
            (string) Str::uuid(), 'group', $this->groupId, $this->roleId, 'group', $this->groupId, false, null, null, $this->admin->id
        ));
        $aggregateUuid = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'group-access:'.$this->groupId)->toString();
        $this->assertSame(
            ['feature.assigned_to_group'],
            EloquentStoredEvent::query()->where('aggregate_uuid', $aggregateUuid)->orderBy('id')->pluck('event_class')->all(),
        );

        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureBId], $this->admin->id));

        $this->assertSame(
            ['feature.assigned_to_group', 'feature.assigned_to_group', 'feature.removed_from_group'],
            EloquentStoredEvent::query()->where('aggregate_uuid', $aggregateUuid)->orderBy('id')->pluck('event_class')->all(),
        );
        $this->assertSame([$this->featureBId], $this->groupFeatureIds());
    }

    public function test_updating_assignment_to_an_ended_period_removes_the_group_features(): void
    {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId], $this->admin->id));
        $assignmentId = (string) Str::uuid();
        $bus->dispatch(new CreateRoleAssignment(
            $assignmentId, 'group', $this->groupId, $this->roleId, 'group', $this->groupId, false, null, null, $this->admin->id
        ));
        $this->assertSame([$this->featureAId], $this->groupFeatureIds());

        $bus->dispatch(new UpdateRoleAssignment($assignmentId, 'group', $this->groupId, false, null, now()->subDay()->toISOString(), $this->admin->id));

        $this->assertSame([], $this->groupFeatureIds());
    }

    public function test_user_subject_assignment_does_not_sync_any_group(): void
    {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId], $this->admin->id));
        $bus->dispatch(new CreateRoleAssignment(
            (string) Str::uuid(), 'user', $this->admin->id, $this->roleId, 'group', $this->groupId, false, null, null, $this->admin->id
        ));

        $this->assertSame([], $this->groupFeatureIds());
    }

    public function test_resync_command_records_only_the_missing_difference_and_is_idempotent(): void
    {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeRoleFeatures($this->roleId, [$this->featureAId], $this->admin->id));
        $bus->dispatch(new CreateRoleAssignment(
            (string) Str::uuid(), 'group', $this->groupId, $this->roleId, 'group', $this->groupId, false, null, null, $this->admin->id
        ));
        // 割当の行だけ欠けた状態(イベントを経ない手動の差)を作り、再同期で埋まることを確かめる。
        DB::table('group_feature_assignments')->where('group_id', $this->groupId)->delete();

        Artisan::call('access-control:resync-group-features');
        $this->assertSame([$this->featureAId], $this->groupFeatureIds());

        $eventsBefore = EloquentStoredEvent::query()->count();
        Artisan::call('access-control:resync-group-features');
        $this->assertSame($eventsBefore, EloquentStoredEvent::query()->count());
    }
}
