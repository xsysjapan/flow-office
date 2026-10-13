<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\AccessControl\Aggregates\RoleAggregate;
use App\Domain\AccessControl\Aggregates\RoleAssignmentAggregate;
use App\Domain\UserManagement\Aggregates\GroupAggregate;
use App\Domain\UserManagement\Aggregates\GroupTypeAggregate;
use App\Domain\UserManagement\Aggregates\UserMembershipAggregate;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * 論点19 決定2: RoleCreated/GroupTypeCreatedのisSystemと、actorUserIdのnull可
 * (シーダー・作り直しの補完は操作者nullでイベントを記録する)。
 */
class SeederEventNullActorTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_created_writes_is_system_from_the_event(): void
    {
        RoleAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:sys')->toString())
            ->create(9001, 'sys', 'System', null, null, true)->persist();
        RoleAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:normal')->toString())
            ->create(9002, 'normal', 'Normal', null, null)->persist();

        $this->assertTrue((bool) DB::table('roles')->where('id', 9001)->value('is_system'));
        $this->assertFalse((bool) DB::table('roles')->where('id', 9002)->value('is_system'));
    }

    public function test_group_type_created_writes_is_system_from_the_event(): void
    {
        GroupTypeAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:sys')->toString())
            ->create(9001, 'sys', 'System', 1, 'unlimited', null, false, null, null, true)->persist();

        $this->assertTrue((bool) DB::table('group_types')->where('id', 9001)->value('is_system'));
    }

    public function test_legacy_payload_without_is_system_is_restored_as_false(): void
    {
        RoleAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:legacy')->toString())
            ->create(9003, 'legacy', 'Legacy', null, null, true)->persist();
        GroupTypeAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:legacy')->toString())
            ->create(9003, 'legacy', 'Legacy', 1, 'unlimited', null, false, null, null, true)->persist();

        foreach (['role.created', 'group_type.created'] as $eventClass) {
            $row = DB::table('stored_events')->where('event_class', $eventClass)->first();
            $properties = json_decode($row->event_properties, true);
            unset($properties['isSystem']);
            DB::table('stored_events')->where('id', $row->id)->update(['event_properties' => json_encode($properties)]);
        }
        DB::table('roles')->delete();
        DB::table('group_types')->delete();

        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $this->assertFalse((bool) DB::table('roles')->where('id', 9003)->value('is_system'));
        $this->assertFalse((bool) DB::table('group_types')->where('id', 9003)->value('is_system'));
    }

    public function test_events_with_null_actor_are_projected_with_null_columns(): void
    {
        $user = User::factory()->create();
        $groupId = Uuid::uuid4()->toString();
        GroupTypeAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:team')->toString())
            ->create(9100, 'team', 'Team', 1, 'unlimited', null, false, null, null)->persist();
        GroupAggregate::retrieve($groupId)->create(9100, 'Group', 'GRP', null, null, null)->persist();
        UserMembershipAggregate::retrieve(UserManagementStreamId::for('user-membership', $user->id))
            ->add($user->id, $groupId, 'member', false, null)->persist();
        $roleStream = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:r')->toString();
        RoleAggregate::retrieve($roleStream)->create(9100, 'r', 'R', null, null)->persist();
        RoleAggregate::retrieve($roleStream)->changePermissions(9100, [], null)->persist();
        $assignmentId = Uuid::uuid4()->toString();
        RoleAssignmentAggregate::retrieve($assignmentId)
            ->create('user', $user->id, 9100, 'global', null, false, null, null, null)->persist();

        $this->assertNull(DB::table('memberships')->where('user_id', $user->id)->value('created_by'));
        $this->assertSame(1, DB::table('memberships')->where('user_id', $user->id)->count());
        $this->assertNull(DB::table('role_assignments')->where('id', $assignmentId)->value('assigned_by'));
        $this->assertSame(1, DB::table('role_assignments')->where('id', $assignmentId)->count());
        $this->assertSame(1, DB::table('groups')->where('id', $groupId)->count());
        $this->assertSame(0, DB::table('permission_role')->where('role_id', 9100)->count());
    }
}
