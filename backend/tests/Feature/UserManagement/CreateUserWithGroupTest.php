<?php

namespace Tests\Feature\UserManagement;

use App\Domain\UserManagement\Commands\CreateUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * 利用者の手動作成 `POST /api/users` の group_id(docs/changesets/20261128-remaining-entrypoints/spec.md 論点1)。
 */
class CreateUserWithGroupTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->assignRole($this->admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']));
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
    }

    private function makeGroup(bool $requiresPrimary): string
    {
        $typeId = DB::table('group_types')->insertGetId([
            'code' => 'T'.Str::random(6), 'name' => '種別', 'status' => 'active',
            'requires_primary_membership' => $requiresPrimary, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $typeId, 'name' => 'G', 'code' => 'G'.Str::random(6), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $groupId;
    }

    public function test_creates_user_and_membership_with_a_single_top_level_command(): void
    {
        $groupId = $this->makeGroup(false);
        $bus = $this->recordCommands();

        $response = $this->actingAs($this->admin)->postJson('/api/users', ['name' => '新規', 'email' => 'g1@example.com', 'group_id' => $groupId]);

        $response->assertCreated();
        $this->assertSingleTopLevelCommand($bus, CreateUser::class);
        $userId = $response->json('id');
        $this->assertDatabaseHas('memberships', ['user_id' => $userId, 'group_id' => $groupId]);
        $allUsers = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $this->assertDatabaseHas('memberships', ['user_id' => $userId, 'group_id' => $allUsers]);
    }

    public function test_rejected_membership_leaves_no_user_and_no_events(): void
    {
        // 主所属が必須の種別へ主所属でない所属を足す検査に落ちる
        $groupId = $this->makeGroup(true);
        $events = DB::table('stored_events')->count();

        $this->actingAs($this->admin)->postJson('/api/users', ['name' => '新規', 'email' => 'g2@example.com', 'group_id' => $groupId])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'g2@example.com']);
        $this->assertSame($events, DB::table('stored_events')->count());
    }

    public function test_all_users_group_as_group_id_rejects_the_whole_creation(): void
    {
        $allUsers = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $events = DB::table('stored_events')->count();

        $this->actingAs($this->admin)->postJson('/api/users', ['name' => '新規', 'email' => 'g3@example.com', 'group_id' => $allUsers])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'g3@example.com']);
        $this->assertSame($events, DB::table('stored_events')->count());
    }
}
