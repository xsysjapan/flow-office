<?php

namespace Tests\Feature\UserManagement;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * membership_change_sets.effective_at は表で最初の TIMESTAMP 列だったため、MySQL(explicit_defaults_for_timestamp=OFF)では
 * 既定値の自動付与で、適用の投影(effective_at を含まないUPDATE)のたびに更新時刻へ上書きされた。
 * 列は DATETIME に変更済み(2026_10_14_000002)。適用後も payload の effectiveAt のまま残ることを固定する。
 * (SQLite では ON UPDATE が起きないため、このテストは回帰の固定が目的。)
 */
class MembershipChangeSetEffectiveAtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access_control.allow_unconfigured_catalog' => false]);
    }

    public function test_effective_at_keeps_the_payload_value_after_the_change_set_is_applied(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']));
        $user = User::factory()->create();
        $typeId = DB::table('group_types')->insertGetId(['code' => 'EFFECTIVE_AT', 'name' => '適用日確認', 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $typeId, 'name' => 'E1', 'code' => 'E1', 'created_at' => now(), 'updated_at' => now()]);

        $created = $this->actingAs($admin)->postJson('/api/admin/user-management/membership-change-sets', [
            'user_id' => $user->id,
            'effective_at' => now()->addDay()->toISOString(),
            'source_type' => 'manual',
            'items' => [['operation' => 'add', 'group_type_id' => $typeId, 'target_group_id' => $groupId, 'is_primary' => false]],
        ])->assertCreated();
        $changeSetId = $created->json('id');

        $payloadEffectiveAt = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $changeSetId)
            ->where('event_class', 'membership_change_set.scheduled')
            ->firstOrFail()
            ->event_properties['effectiveAt'];
        $this->assertSame($payloadEffectiveAt, DB::table('membership_change_sets')->where('id', $changeSetId)->value('effective_at'));

        $this->actingAs($admin)->postJson("/api/admin/user-management/membership-change-sets/{$changeSetId}/apply")->assertOk();

        $this->assertDatabaseHas('membership_change_sets', ['id' => $changeSetId, 'status' => 'applied']);
        $this->assertSame(
            $payloadEffectiveAt,
            DB::table('membership_change_sets')->where('id', $changeSetId)->value('effective_at'),
        );
    }
}
