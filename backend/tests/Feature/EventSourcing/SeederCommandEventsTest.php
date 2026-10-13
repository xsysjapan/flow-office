<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\AccessControl\Commands\ChangeRolePermissions;
use App\Domain\AccessControl\Commands\RemoveFeatureFromGroup;
use App\Domain\EventSourcing\CommandBus;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 論点19 決定4: UserManagementSeeder・AccessControlSeederはCommand(操作者null)を発行してイベントを記録し、
 * 既にある行は飛ばす(冪等・上書きしない)。
 */
class SeederCommandEventsTest extends TestCase
{
    use RefreshDatabase;

    private const READ_MODELS = [
        'group_types', 'groups', 'roles', 'permission_role', 'role_assignments',
        'group_feature_assignments', 'memberships', 'external_identities', 'field_authorities',
    ];

    private function seedAll(): void
    {
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
    }

    /** @return array<string, list<string>> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::READ_MODELS as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(function ($row) use ($table) {
                $row = (array) $row;
                unset($row['created_at'], $row['updated_at'], $row['linked_at']);
                if (in_array($table, ['memberships', 'external_identities', 'field_authorities', 'group_feature_assignments'], true)) {
                    unset($row['id']);
                }
                ksort($row);

                return json_encode($row);
            })->sort()->values()->all();
        }

        return $snapshot;
    }

    public function test_seeders_record_events_for_every_projected_row(): void
    {
        User::factory()->create(['entra_user_id' => 'entra-seed-user']);

        $this->seedAll();

        $expected = [
            'group_type.created' => ['group_types', 7],
            'group.created' => ['groups', 4],
            'role.created' => ['roles', 6],
            'role.permissions_changed' => [null, 6],
            'role_assignment.created' => ['role_assignments', 4],
            'feature.assigned_to_group' => ['group_feature_assignments', 15],
            'membership.added' => ['memberships', 1],
            'external_identity.linked' => ['external_identities', 1],
            'user.field_authority_changed' => ['field_authorities', 10],
        ];
        foreach ($expected as $eventClass => [$table, $count]) {
            $this->assertSame($count, DB::table('stored_events')->where('event_class', $eventClass)->count(), $eventClass);
            if ($table !== null) {
                $this->assertSame($count, DB::table($table)->count(), $table);
            }
        }
        $this->assertGreaterThan(0, DB::table('permission_role')->count());
        foreach (DB::table('stored_events')->whereIn('event_class', ['group.created', 'role.created', 'membership.added'])->pluck('event_properties') as $properties) {
            $this->assertNull(json_decode($properties, true)['actorUserId'] ?? null);
        }
        $this->assertSame(
            DB::table('group_types')->count(),
            DB::table('group_types')->where('is_system', true)->count(),
        );
    }

    public function test_replaying_the_events_rebuilds_the_same_rows(): void
    {
        User::factory()->create(['entra_user_id' => 'entra-seed-user']);
        $this->seedAll();
        $before = $this->snapshot();

        foreach (['permission_role', 'role_assignments', 'group_feature_assignments', 'memberships', 'external_identities', 'field_authorities', 'groups', 'group_types', 'roles'] as $table) {
            DB::table($table)->delete();
        }
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_running_the_seeders_twice_does_not_add_events(): void
    {
        User::factory()->create(['entra_user_id' => 'entra-seed-user']);
        $this->seedAll();
        $events = DB::table('stored_events')->count();
        $before = $this->snapshot();

        $this->seedAll();

        $this->assertSame($events, DB::table('stored_events')->count());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_rerunning_the_seeders_does_not_overwrite_settings_changed_by_an_administrator(): void
    {
        $this->seedAll();
        $roleId = (int) DB::table('roles')->where('code', 'employee')->value('id');
        $readId = (int) DB::table('permissions')->where('code', 'attendance.read')->value('id');
        app(CommandBus::class)->dispatch(new ChangeRolePermissions($roleId, [$readId], null));

        $groupId = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $featureId = (int) DB::table('features')->where('code', 'attendance.clock')->value('id');
        app(CommandBus::class)->dispatch(new RemoveFeatureFromGroup($groupId, $featureId, null));

        $this->seedAll();

        $this->assertSame([$readId], DB::table('permission_role')->where('role_id', $roleId)->pluck('permission_id')->map(fn ($id) => (int) $id)->all());
        $this->assertFalse(DB::table('group_feature_assignments')->where('group_id', $groupId)->where('feature_id', $featureId)->exists());
    }
}
