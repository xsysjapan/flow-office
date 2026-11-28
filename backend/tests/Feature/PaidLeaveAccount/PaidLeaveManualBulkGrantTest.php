<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeaveToUsers;
use App\Models\PaidLeaveGrant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\Support\CreatesGroupWithMembers;
use Tests\TestCase;

/**
 * 有給の手動付与の一括(POST /paid-leave/grants/bulk)。入口1回=1つのCommand、全部か無しか、
 * グループの指定の展開(実行時点の直接の所属・退職者を除く)、同じrequest_idの再送の冪等。
 * docs/changesets/20261128-bulk-operations/spec.md 論点3。
 */
class PaidLeaveManualBulkGrantTest extends TestCase
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

    /** @return array<string, mixed> */
    private function body(array $extra = []): array
    {
        return $extra + [
            'request_id' => (string) Str::uuid(),
            'granted_on' => '2026-07-01',
            'expires_on' => '2028-07-01',
            'granted_days' => 10,
            'grant_reason' => '一括付与',
        ];
    }

    public function test_grants_to_every_user_with_a_single_command(): void
    {
        $hr = $this->hr();
        $users = User::factory()->count(3)->create();

        $bus = $this->recordCommands();
        $response = $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['user_ids' => $users->pluck('id')->all()]));

        $response->assertOk();
        $this->assertEqualsCanonicalizing($users->pluck('id')->all(), array_column($response->json('data'), 'user_id'));
        $this->assertSingleTopLevelCommand($bus, GrantPaidLeaveToUsers::class);
        foreach ($users as $user) {
            $this->assertSame(1, PaidLeaveGrant::query()->where('user_id', $user->id)->count());
        }
    }

    public function test_one_failure_records_nothing_and_422_names_the_user(): void
    {
        $hr = $this->hr();
        [$ok, $bad] = User::factory()->count(2)->create(['name' => '田中太郎'])->all();
        $bad->update(['name' => '失敗する人']);
        // 失敗する利用者: 最新の付与より前の日付の付与は集約が拒否する。
        $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $bad->id, 'granted_on' => '2026-09-01', 'expires_on' => '2028-09-01', 'granted_days' => 10,
        ])->assertCreated();
        $before = DB::table('stored_events')->count();

        $response = $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['user_ids' => [$ok->id, $bad->id]]));

        $response->assertUnprocessable();
        $response->assertJsonPath('code', 'bulk_operation_rejected');
        $this->assertSame([$bad->id], array_column($response->json('items'), 'id'));
        $this->assertSame('失敗する人', $response->json('items.0.label'));
        $this->assertNotEmpty($response->json('errors')["items.{$bad->id}"]);
        $this->assertSame($before, DB::table('stored_events')->count());
        $this->assertSame(0, PaidLeaveGrant::query()->where('user_id', $ok->id)->count());
    }

    public function test_every_failure_is_reported(): void
    {
        $hr = $this->hr();
        $users = User::factory()->count(3)->create();
        foreach ([$users[0], $users[1]] as $user) {
            $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
                'user_id' => $user->id, 'granted_on' => '2026-09-01', 'expires_on' => '2028-09-01', 'granted_days' => 10,
            ])->assertCreated();
        }

        $response = $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['user_ids' => $users->pluck('id')->all()]));

        $response->assertUnprocessable();
        $this->assertEqualsCanonicalizing([$users[0]->id, $users[1]->id], array_column($response->json('items'), 'id'));
    }

    public function test_limit_and_distinct_are_validated(): void
    {
        $hr = $this->hr();
        $user = User::factory()->create();

        $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['user_ids' => [$user->id, $user->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_ids.0', 'user_ids.1']);

        $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['user_ids' => array_map(fn () => (string) Str::uuid(), range(1, 501))]))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_ids']);
    }

    public function test_group_is_expanded_to_current_direct_active_members_only(): void
    {
        $hr = $this->hr();
        $active = User::factory()->create();
        $retiredByStatus = User::factory()->create(['employment_status' => 'retired']);
        $retiredByDate = User::factory()->create(['termination_date' => '2020-01-31']);
        $childMember = User::factory()->create();
        $parent = $this->makeGroupWithMembers([$active, $retiredByStatus, $retiredByDate]);
        $this->makeGroupWithMembers([$childMember], $parent);

        $response = $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['group_id' => $parent]));

        $response->assertOk();
        $this->assertSame([$active->id], array_column($response->json('data'), 'user_id'));
        $this->assertSame(1, PaidLeaveGrant::query()->count());
        $this->assertSame(1, PaidLeaveGrant::query()->where('user_id', $active->id)->count());
    }

    public function test_group_expansion_uses_the_members_at_execution_time(): void
    {
        $hr = $this->hr();
        $first = User::factory()->create();
        $joinedLater = User::factory()->create();
        $groupId = $this->makeGroupWithMembers([$first]);

        $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['group_id' => $groupId]))->assertOk();
        $this->assertSame(0, PaidLeaveGrant::query()->where('user_id', $joinedLater->id)->count());

        DB::table('memberships')->insert(['user_id' => $joinedLater->id, 'group_id' => $groupId, 'membership_kind' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['group_id' => $groupId]))->assertOk();

        $this->assertSame(1, PaidLeaveGrant::query()->where('user_id', $joinedLater->id)->count());
    }

    public function test_group_without_target_members_is_rejected(): void
    {
        $hr = $this->hr();
        $retired = User::factory()->create(['employment_status' => 'retired']);
        $groupId = $this->makeGroupWithMembers([$retired]);

        $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['group_id' => $groupId]))
            ->assertUnprocessable()->assertJsonValidationErrors(['group_id']);
    }

    public function test_specifying_a_group_requires_group_view(): void
    {
        $manager = User::factory()->create();
        $this->grantSelfPermission($manager, 'leave.manage');
        $member = User::factory()->create();
        $groupId = $this->makeGroupWithMembers([$member]);

        $this->actingAs($manager)->postJson('/api/paid-leave/grants/bulk', $this->body(['group_id' => $groupId]))->assertForbidden();
        $this->assertSame(0, PaidLeaveGrant::query()->count());

        // 個人の指定は group.view を要しない。
        $this->actingAs($manager)->postJson('/api/paid-leave/grants/bulk', $this->body(['user_ids' => [$member->id]]))->assertOk();
    }

    public function test_resending_the_same_request_id_does_not_grant_twice_and_succeeds(): void
    {
        $hr = $this->hr();
        $users = User::factory()->count(2)->create();
        $body = $this->body(['user_ids' => $users->pluck('id')->all()]);

        $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $body)->assertOk();
        $eventCount = DB::table('stored_events')->count();
        $second = $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $body);

        $second->assertOk();
        $this->assertCount(2, $second->json('data'));
        $this->assertSame($eventCount, DB::table('stored_events')->count());
        $this->assertSame(2, PaidLeaveGrant::query()->count());

        // 別のrequest_idなら別の付与になる。
        $this->actingAs($hr)->postJson('/api/paid-leave/grants/bulk', $this->body(['user_ids' => $users->pluck('id')->all()]))->assertOk();
        $this->assertSame(4, PaidLeaveGrant::query()->count());
    }

    public function test_single_grant_with_request_id_is_idempotent_and_without_it_grants_each_time(): void
    {
        $hr = $this->hr();
        $user = User::factory()->create();
        $payload = ['user_id' => $user->id, 'granted_on' => '2026-07-01', 'expires_on' => '2028-07-01', 'granted_days' => 10];
        $requestId = (string) Str::uuid();

        $first = $this->actingAs($hr)->postJson('/api/paid-leave/grants', $payload + ['request_id' => $requestId])->assertCreated()->json('id');
        $second = $this->actingAs($hr)->postJson('/api/paid-leave/grants', $payload + ['request_id' => $requestId])->assertCreated()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, PaidLeaveGrant::query()->where('user_id', $user->id)->count());

        $this->actingAs($hr)->postJson('/api/paid-leave/grants', $payload)->assertCreated();
        $this->assertSame(2, PaidLeaveGrant::query()->where('user_id', $user->id)->count());
    }

    public function test_employee_without_leave_manage_is_forbidden(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/paid-leave/grants/bulk', $this->body(['user_ids' => [$employee->id]]))->assertForbidden();
    }
}
