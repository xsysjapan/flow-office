<?php

namespace Tests\Feature\Asset;

use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AssetManagementType;
use App\Models\AssetNumberRule;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 管理番号自動採番(docs/changesets/20260831-asset-management-refinement/spec.md 論点10)。
 * `RegisterAssetHandler`のカテゴリ一致採番/デフォルト採番/個別ルールOFF時の手入力
 * フォールバック/ルール0件時の手入力の4分岐と、連番の行ロック(同時実行での重複無し)を検証する。
 */
class AssetNumberingTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function register(User $user, string $category, ?string $assetNo = null): string
    {
        $asset = $this->bus()->dispatch(new RegisterAsset(
            assetNo: $assetNo,
            name: 'テスト備品',
            category: $category,
            serialNumber: null,
            managementType: AssetManagementType::INSTALLATION,
            lendingMethod: null,
            defaultLocationText: null,
            notes: null,
            registeredByUserId: $user->id,
        ));

        return $asset->asset_no;
    }

    public function test_registers_with_manual_number_when_no_rules_exist(): void
    {
        $user = User::factory()->create();

        $assetNo = $this->register($user, 'ノートPC', 'MANUAL-0001');

        $this->assertSame('MANUAL-0001', $assetNo);
    }

    public function test_throws_validation_error_when_no_rules_exist_and_asset_no_omitted(): void
    {
        $user = User::factory()->create();

        $this->expectException(DomainRuleException::class);

        $this->register($user, 'ノートPC');
    }

    public function test_issues_sequential_number_from_matching_category_rule(): void
    {
        $user = User::factory()->create();
        AssetNumberRule::query()->create(['category' => 'ノートPC', 'prefix' => 'NPC', 'digit_count' => 5, 'next_number' => 1, 'enabled' => true]);

        $first = $this->register($user, 'ノートPC');
        $second = $this->register($user, 'ノートPC');

        $this->assertSame('NPC-00001', $first);
        $this->assertSame('NPC-00002', $second);
    }

    public function test_falls_back_to_default_rule_when_no_category_rule_matches(): void
    {
        $user = User::factory()->create();
        AssetNumberRule::query()->create(['category' => null, 'is_default' => true, 'prefix' => 'AST', 'digit_count' => 5, 'next_number' => 1, 'enabled' => true]);

        $assetNo = $this->register($user, '未登録カテゴリ');

        $this->assertSame('AST-00001', $assetNo);
    }

    public function test_disabled_category_rule_does_not_fall_back_to_default_and_requires_manual_input(): void
    {
        $user = User::factory()->create();
        AssetNumberRule::query()->create(['category' => 'ノートPC', 'prefix' => 'NPC', 'digit_count' => 5, 'next_number' => 1, 'enabled' => false]);
        AssetNumberRule::query()->create(['category' => null, 'is_default' => true, 'prefix' => 'AST', 'digit_count' => 5, 'next_number' => 1, 'enabled' => true]);

        $this->expectException(DomainRuleException::class);

        $this->register($user, 'ノートPC');
    }

    public function test_disabled_category_rule_allows_manual_input(): void
    {
        $user = User::factory()->create();
        AssetNumberRule::query()->create(['category' => 'ノートPC', 'prefix' => 'NPC', 'digit_count' => 5, 'next_number' => 1, 'enabled' => false]);

        $assetNo = $this->register($user, 'ノートPC', 'MANUAL-9999');

        $this->assertSame('MANUAL-9999', $assetNo);
    }

    public function test_concurrent_issuance_does_not_produce_duplicate_numbers(): void
    {
        $user = User::factory()->create();
        AssetNumberRule::query()->create(['category' => 'ノートPC', 'prefix' => 'NPC', 'digit_count' => 5, 'next_number' => 1, 'enabled' => true]);

        // sqliteの単一接続上では真の並行実行を再現できないため、lockForUpdateを含む
        // ハンドラを連続で複数回呼び出し、払い出された番号が重複なく連番になることを確認する
        // (行ロック自体の機能検証は`IssueAssetNumberHandler`が同一トランザクション内で
        // next_numberを読み取り→インクリメントしていることに依存する)。
        $numbers = [];
        for ($i = 0; $i < 10; $i++) {
            $numbers[] = $this->register($user, 'ノートPC');
        }

        $this->assertCount(10, array_unique($numbers));
        $this->assertSame('NPC-00001', $numbers[0]);
        $this->assertSame('NPC-00010', $numbers[9]);
    }

    public function test_api_configure_and_issue_category_rule(): void
    {
        $user = User::factory()->create();
        $this->grantAssetManage($user);

        $response = $this->actingAs($user)->putJson('/api/asset-number-rules/ノートPC', [
            'prefix' => 'NPC',
            'digit_count' => 5,
            'enabled' => true,
        ]);
        $this->assertContains($response->getStatusCode(), [200, 201]);
        $response->assertJsonPath('category', 'ノートPC');
        $response->assertJsonPath('prefix', 'NPC');

        $registerResponse = $this->actingAs($user)->postJson('/api/assets', [
            'name' => 'テストPC',
            'category' => 'ノートPC',
            'management_type' => AssetManagementType::INSTALLATION,
        ]);
        $registerResponse->assertCreated();
        $this->assertSame('NPC-00001', $registerResponse->json('asset_no'));

        $listResponse = $this->actingAs($user)->getJson('/api/asset-number-rules');
        $listResponse->assertOk();

        $categoriesResponse = $this->actingAs($user)->getJson('/api/asset-number-rules/categories');
        $categoriesResponse->assertOk();
        $this->assertContains('ノートPC', array_values($categoriesResponse->json('data')));
    }

    /**
     * `AssetApiTest::grantAssetManage`と同じ内容(asset.manageグローバル権限をこのユーザー専用の
     * Roleへ付与する)。複数テストクラスからDRYに共有する仕組みが無いため複製している。
     */
    private function grantAssetManage(User $user): void
    {
        DB::table('permissions')->updateOrInsert(
            ['code' => 'asset.manage'],
            ['resource' => 'asset', 'action' => 'manage', 'created_at' => now(), 'updated_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('code', 'asset.manage')->value('id');
        DB::table('permission_scope_types')->insertOrIgnore(['permission_id' => $permissionId, 'scope_type' => 'global']);

        $role = Role::query()->create(['code' => 'ASSET_MANAGER_'.Str::upper(Str::random(12)), 'name' => 'Asset Manager (test)']);
        DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);

        RoleAssignment::query()->create([
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'global',
            'scope_group_id' => null,
            'include_descendants' => false,
            'status' => 'active',
            'assigned_by' => $user->id,
        ]);
    }
}
