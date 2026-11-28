<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeaveToUsers;
use App\Models\Role;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\Support\CreatesGroupWithMembers;
use Tests\Support\FailingGrantSpecialLeaveHandler;
use Tests\TestCase;

/**
 * 特別休暇の手動付与の一括(POST /special-leave/grants/bulk)。入口1回=1つのCommand、全部か無しか、
 * グループの指定の展開、同じrequest_idの再送の冪等。docs/changesets/20261128-bulk-operations/spec.md 論点3。
 */
class SpecialLeaveManualBulkGrantTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use CreatesGroupWithMembers;
    use RefreshDatabase;

    private function hr(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    private function type(): SpecialLeaveType
    {
        return SpecialLeaveType::query()->create(['name' => '慶弔休暇', 'is_active' => true, 'requires_grant' => true]);
    }

    /** @return array<string, mixed> */
    private function body(SpecialLeaveType $type, array $extra = []): array
    {
        return $extra + [
            'request_id' => (string) Str::uuid(),
            'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01',
            'granted_days' => 3,
            'grant_reason' => '一括付与',
        ];
    }

    public function test_grants_to_every_user_with_a_single_command(): void
    {
        $hr = $this->hr();
        $type = $this->type();
        $users = User::factory()->count(3)->create();

        $bus = $this->recordCommands();
        $response = $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $this->body($type, ['user_ids' => $users->pluck('id')->all()]));

        $response->assertOk();
        $this->assertEqualsCanonicalizing($users->pluck('id')->all(), array_column($response->json('data'), 'user_id'));
        $this->assertSingleTopLevelCommand($bus, GrantSpecialLeaveToUsers::class);
        $this->assertSame(3, SpecialLeaveGrant::query()->count());
    }

    public function test_failures_record_nothing_and_422_lists_every_failed_user_with_the_name(): void
    {
        $hr = $this->hr();
        $type = $this->type();
        $ok = User::factory()->create();
        $badA = User::factory()->create(['name' => '甲野']);
        $badB = User::factory()->create(['name' => '乙野']);
        config(['domain.command_handlers.'.GrantSpecialLeave::class => FailingGrantSpecialLeaveHandler::class]);
        FailingGrantSpecialLeaveHandler::$failingUserIds = [$badA->id, $badB->id];
        $before = DB::table('stored_events')->count();

        $response = $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $this->body($type, ['user_ids' => [$ok->id, $badA->id, $badB->id]]));

        FailingGrantSpecialLeaveHandler::$failingUserIds = [];
        $response->assertUnprocessable();
        $response->assertJsonPath('code', 'bulk_operation_rejected');
        $this->assertEqualsCanonicalizing([$badA->id, $badB->id], array_column($response->json('items'), 'id'));
        $this->assertEqualsCanonicalizing(['甲野', '乙野'], array_column($response->json('items'), 'label'));
        $this->assertNotEmpty($response->json('errors')["items.{$badA->id}"]);
        $this->assertSame($before, DB::table('stored_events')->count());
        $this->assertSame(0, SpecialLeaveGrant::query()->count());
    }

    public function test_limit_distinct_and_unknown_users_are_validated(): void
    {
        $hr = $this->hr();
        $type = $this->type();
        $user = User::factory()->create();

        $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $this->body($type, ['user_ids' => [$user->id, $user->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_ids.0', 'user_ids.1']);
        $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $this->body($type, ['user_ids' => array_map(fn () => (string) Str::uuid(), range(1, 501))]))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_ids']);
        $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $this->body($type, ['user_ids' => [$user->id, (string) Str::uuid()]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_ids.1']);
        $this->assertSame(0, SpecialLeaveGrant::query()->count());
    }

    public function test_group_is_expanded_to_current_direct_active_members_only(): void
    {
        $hr = $this->hr();
        $type = $this->type();
        $active = User::factory()->create();
        $retired = User::factory()->create(['employment_status' => 'retired']);
        $childMember = User::factory()->create();
        $parent = $this->makeGroupWithMembers([$active, $retired]);
        $this->makeGroupWithMembers([$childMember], $parent);

        $response = $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $this->body($type, ['group_id' => $parent]));

        $response->assertOk();
        $this->assertSame([$active->id], array_column($response->json('data'), 'user_id'));
        $this->assertSame([$active->id], SpecialLeaveGrant::query()->pluck('user_id')->all());

        // 実行時点の所属で展開する(後から入った人は次の実行で対象になる)。
        DB::table('memberships')->insert(['user_id' => $childMember->id, 'group_id' => $parent, 'membership_kind' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $this->body($type, ['group_id' => $parent]))->assertOk();
        $this->assertSame(1, SpecialLeaveGrant::query()->where('user_id', $childMember->id)->count());
    }

    public function test_specifying_a_group_requires_group_view(): void
    {
        $manager = User::factory()->create();
        $this->grantSelfPermission($manager, 'leave.manage');
        $type = $this->type();
        $member = User::factory()->create();
        $groupId = $this->makeGroupWithMembers([$member]);

        $this->actingAs($manager)->postJson('/api/special-leave/grants/bulk', $this->body($type, ['group_id' => $groupId]))->assertForbidden();
        $this->assertSame(0, SpecialLeaveGrant::query()->count());
    }

    public function test_resending_the_same_request_id_does_not_grant_twice_and_succeeds(): void
    {
        $hr = $this->hr();
        $type = $this->type();
        $users = User::factory()->count(2)->create();
        $body = $this->body($type, ['user_ids' => $users->pluck('id')->all()]);

        $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $body)->assertOk();
        $eventCount = DB::table('stored_events')->count();
        $second = $this->actingAs($hr)->postJson('/api/special-leave/grants/bulk', $body);

        $second->assertOk();
        $this->assertCount(2, $second->json('data'));
        $this->assertSame($eventCount, DB::table('stored_events')->count());
        $this->assertSame(2, SpecialLeaveGrant::query()->count());
    }

    public function test_single_grant_with_request_id_is_idempotent(): void
    {
        $hr = $this->hr();
        $type = $this->type();
        $user = User::factory()->create();
        $payload = ['user_id' => $user->id, 'special_leave_type_id' => $type->id, 'granted_on' => '2026-07-01', 'granted_days' => 3, 'request_id' => (string) Str::uuid()];

        $first = $this->actingAs($hr)->postJson('/api/special-leave/grants', $payload)->assertCreated()->json('id');
        $second = $this->actingAs($hr)->postJson('/api/special-leave/grants', $payload)->assertCreated()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, SpecialLeaveGrant::query()->count());

        unset($payload['request_id']);
        $this->actingAs($hr)->postJson('/api/special-leave/grants', $payload)->assertCreated();
        $this->assertSame(2, SpecialLeaveGrant::query()->count());
    }
}
