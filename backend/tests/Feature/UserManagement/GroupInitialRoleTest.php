<?php

namespace Tests\Feature\UserManagement;

use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveRoleAssignment;
use App\Domain\AccessControl\Commands\UpdateRoleAssignment;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\CreateGroup;
use App\Domain\UserManagement\Events\GroupCreated;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * グループの作成と初期ロール(docs/changesets/20261128-remaining-entrypoints/spec.md 論点4)。
 * 入口1回=CreateGroup1つで、AccessControlのReactorが全社の割当を作る。割当が拒否されるとグループも残らない。
 */
class GroupInitialRoleTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access_control.allow_unconfigured_catalog' => false]);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->where('code', Role::ADMIN)->firstOrFail());

        return $admin;
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'group_type_id' => DB::table('group_types')->value('id'),
            'name' => '新規グループ',
        ], $extra);
    }

    private function assignableRoleId(): int
    {
        return (int) Role::query()->where('code', Role::ADMIN)->value('id');
    }

    public function test_create_with_initial_role_dispatches_one_command_and_records_global_assignment(): void
    {
        $admin = $this->admin();
        $bus = $this->recordCommands();

        $response = $this->actingAs($admin)->postJson('/api/admin/user-management/groups', $this->payload(['initial_role_id' => $this->assignableRoleId()]))->assertCreated();

        $this->assertSingleTopLevelCommand($bus, CreateGroup::class);
        $groupId = $response->json('id');
        $assignment = DB::table('role_assignments')->where('subject_type', 'group')->where('subject_id', $groupId)->sole();
        $this->assertSame(Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-initial-role:'.$groupId)->toString(), $assignment->id);
        $this->assertSame($this->assignableRoleId(), (int) $assignment->role_id);
        $this->assertSame('global', $assignment->scope_type);
        $this->assertNull($assignment->scope_group_id);
        $this->assertFalse((bool) $assignment->includes_descendants);
        $this->assertNull($assignment->starts_at);
        $this->assertNull($assignment->ends_at);
        $this->assertSame('active', $assignment->status);
        $this->assertSame($admin->id, $assignment->assigned_by);
        $this->assertSame(1, EloquentStoredEvent::query()->where('event_class', 'role_assignment.created')->where('aggregate_uuid', $assignment->id)->count());
    }

    public function test_create_without_initial_role_creates_no_assignment(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/admin/user-management/groups', $this->payload())->assertCreated();

        $this->assertSame(0, DB::table('role_assignments')->where('subject_id', $response->json('id'))->count());
    }

    public function test_rejected_initial_role_is_a_field_error_and_the_group_is_not_left(): void
    {
        $admin = $this->admin();
        $before = DB::table('groups')->count();
        $eventsBefore = EloquentStoredEvent::query()->count();

        $this->actingAs($admin)->postJson('/api/admin/user-management/groups', $this->payload(['initial_role_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['initial_role_id']);

        $this->assertSame($before, DB::table('groups')->count());
        $this->assertSame($eventsBefore, EloquentStoredEvent::query()->count());
    }

    public function test_user_without_global_role_assign_cannot_create_with_initial_role(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->where('code', Role::HR_STAFF)->firstOrFail());
        $before = DB::table('groups')->count();

        $this->actingAs($hr)->postJson('/api/admin/user-management/groups', $this->payload(['initial_role_id' => $this->assignableRoleId()]))->assertForbidden();
        $this->assertSame($before, DB::table('groups')->count());

        // 初期ロールなしなら作れる(group.createだけで足りる)。
        $this->actingAs($hr)->postJson('/api/admin/user-management/groups', $this->payload())->assertCreated();
    }

    public function test_group_created_reactor_is_idempotent_for_the_same_event(): void
    {
        $admin = $this->admin();
        $groupId = $this->actingAs($admin)->postJson('/api/admin/user-management/groups', $this->payload(['initial_role_id' => $this->assignableRoleId()]))->json('id');
        $assignmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-initial-role:'.$groupId)->toString();

        // 同じ割当IDでviaReactorのCommandをもう一度処理しても、イベントは増えない。
        app(CommandBus::class)->dispatch(new CreateRoleAssignment($assignmentId, 'group', $groupId, $this->assignableRoleId(), 'global', null, false, null, null, $admin->id, true, $admin->id));

        $this->assertSame(1, EloquentStoredEvent::query()->where('aggregate_uuid', $assignmentId)->count());
    }

    public function test_group_created_event_without_initial_role_is_still_readable(): void
    {
        $event = new GroupCreated(1, 'n', 'c', null, null, null);
        $this->assertNull($event->initialRoleId);
    }

    public function test_initial_role_assignment_can_be_updated_and_removed_by_normal_commands(): void
    {
        $admin = $this->admin();
        $groupId = $this->actingAs($admin)->postJson('/api/admin/user-management/groups', $this->payload(['initial_role_id' => $this->assignableRoleId()]))->json('id');
        $assignmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-initial-role:'.$groupId)->toString();

        app(CommandBus::class)->dispatch(new UpdateRoleAssignment($assignmentId, 'global', null, false, null, null, $admin->id));
        app(CommandBus::class)->dispatch(new RemoveRoleAssignment($assignmentId, $admin->id));

        $this->assertSame('removed', DB::table('role_assignments')->where('id', $assignmentId)->value('status'));
    }
}
