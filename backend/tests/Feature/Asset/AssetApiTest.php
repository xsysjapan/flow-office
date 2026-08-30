<?php

namespace Tests\Feature\Asset;

use App\Domain\Asset\Commands\LendAsset;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\EventSourcing\CommandBus;
use App\Models\Asset;
use App\Models\AssetLendingMethod;
use App\Models\AssetManagementType;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 備品管理フェーズ3(API層)のHTTP経由の検証。
 * docs/changesets/20260830-equipment-management/spec.md「テストで重点的に確認すること」。
 */
class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    /**
     * asset.manage(global scope)をこのユーザー専用のRoleに付与する。TestCase::
     * grantSelfPermission()はEmployeeロール(全acting-asユーザーが自動で持つ共有ロール)へ
     * 付与するため、そちらを使うと他のテスト用ユーザーにも意図せず権限が付与されてしまう
     * (asset.manageの許容スコープはglobalのみのため、self scopeの付与とも噛み合わない)。
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

    private function registerAsset(
        User $registeredBy,
        string $lendingMethod = AssetLendingMethod::BACKOFFICE,
        ?string $defaultLocationText = null,
        ?string $name = 'ノートPC',
    ): Asset {
        return $this->bus()->dispatch(new RegisterAsset(
            assetNo: 'EQ-'.uniqid(),
            name: $name,
            category: 'PC',
            serialNumber: 'SN-'.uniqid(),
            managementType: AssetManagementType::LENDING,
            lendingMethod: $lendingMethod,
            defaultLocationText: $defaultLocationText,
            notes: null,
            registeredByUserId: $registeredBy->id,
        ));
    }

    // --- 検索・詳細・QR ---

    public function test_any_authenticated_user_can_search_assets(): void
    {
        $user = User::factory()->create();
        $asset = $this->registerAsset($user, name: '検索対象ノートPC');

        $response = $this->actingAs($user)->getJson('/api/assets?q=検索対象');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $asset->id);
    }

    public function test_any_authenticated_user_can_view_asset_detail(): void
    {
        $user = User::factory()->create();
        $asset = $this->registerAsset($user);

        $response = $this->actingAs($user)->getJson("/api/assets/{$asset->id}");

        $response->assertOk();
        $response->assertJsonPath('id', $asset->id);
        $response->assertJsonPath('asset_no', $asset->asset_no);
    }

    public function test_any_authenticated_user_can_fetch_asset_by_qr_token(): void
    {
        $user = User::factory()->create();
        $asset = $this->registerAsset($user);

        $response = $this->actingAs($user)->getJson("/api/assets/by-qr/{$asset->qr_token}");

        $response->assertOk();
        $response->assertJsonPath('id', $asset->id);
    }

    public function test_asset_history_returns_registration_event(): void
    {
        $user = User::factory()->create();
        $asset = $this->registerAsset($user);

        $response = $this->actingAs($user)->getJson("/api/assets/{$asset->id}/history");

        $response->assertOk();
        $response->assertJsonPath('0.aggregate_id', $asset->id);
    }

    // --- 登録・編集・削除はasset.manage権限保有者のみ ---

    public function test_user_with_asset_manage_permission_can_register_an_asset(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);

        $response = $this->actingAs($manager)->postJson('/api/assets', [
            'asset_no' => 'EQ-NEW-001',
            'name' => 'デスクトップPC',
            'category' => 'PC',
            'management_type' => AssetManagementType::LENDING,
            'lending_method' => AssetLendingMethod::BACKOFFICE,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('asset_no', 'EQ-NEW-001');
    }

    public function test_user_without_asset_manage_permission_cannot_register_an_asset(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/assets', [
            'asset_no' => 'EQ-NEW-002',
            'name' => 'デスクトップPC',
            'category' => 'PC',
            'management_type' => AssetManagementType::LENDING,
            'lending_method' => AssetLendingMethod::BACKOFFICE,
        ]);

        $response->assertForbidden();
    }

    public function test_user_with_asset_manage_permission_can_update_asset_details(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager);

        $response = $this->actingAs($manager)->patchJson("/api/assets/{$asset->id}", [
            'name' => '更新後の名称',
            'category' => 'PC',
            'serial_number' => null,
        ]);

        $response->assertOk();
        $response->assertJsonPath('name', '更新後の名称');
    }

    public function test_user_without_asset_manage_permission_cannot_update_asset_details(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager);

        $employee = User::factory()->create();

        $this->actingAs($employee)->patchJson("/api/assets/{$asset->id}", [
            'name' => '更新後の名称',
            'category' => 'PC',
        ])->assertForbidden();
    }

    public function test_user_with_asset_manage_permission_can_delete_an_asset(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager);

        $response = $this->actingAs($manager)->deleteJson("/api/assets/{$asset->id}");

        $response->assertNoContent();
        $this->assertNull(Asset::query()->find($asset->id));
    }

    public function test_user_without_asset_manage_permission_cannot_delete_an_asset(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager);

        $employee = User::factory()->create();

        $this->actingAs($employee)->deleteJson("/api/assets/{$asset->id}")->assertForbidden();
    }

    // --- self_service貸与 ---

    public function test_employee_can_self_service_lend_an_asset_to_themselves(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager, AssetLendingMethod::SELF_SERVICE, '3階ロッカーA');

        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson("/api/assets/{$asset->id}/lend", [
            'borrower_user_id' => $employee->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('lending_status', 'loaned');
    }

    public function test_employee_cannot_self_service_lend_an_asset_to_someone_else(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager, AssetLendingMethod::SELF_SERVICE, '3階ロッカーA');

        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create();

        $this->actingAs($employee)->postJson("/api/assets/{$asset->id}/lend", [
            'borrower_user_id' => $otherEmployee->id,
        ])->assertForbidden();
    }

    public function test_employee_cannot_lend_a_backoffice_managed_asset_without_permission(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager, AssetLendingMethod::BACKOFFICE);

        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson("/api/assets/{$asset->id}/lend", [
            'borrower_user_id' => $employee->id,
        ])->assertForbidden();
    }

    public function test_manager_can_lend_a_backoffice_managed_asset_to_another_user(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager, AssetLendingMethod::BACKOFFICE);

        $employee = User::factory()->create();

        $response = $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/lend", [
            'borrower_user_id' => $employee->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('lending_status', 'loaned');
    }

    // --- 返却 ---

    public function test_borrower_can_return_a_loaned_asset(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager, AssetLendingMethod::SELF_SERVICE, '3階ロッカーA');
        $employee = User::factory()->create();

        $this->bus()->dispatch(new LendAsset($asset->id, $employee->id, $employee->id));

        $response = $this->actingAs($employee)->postJson("/api/assets/{$asset->id}/return", []);

        $response->assertOk();
        $response->assertJsonPath('lending_status', 'available');
    }

    // --- 一括操作: 部分成功 ---

    public function test_bulk_self_return_handles_partial_success(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $employee = User::factory()->create();

        $loanedAsset = $this->registerAsset($manager, AssetLendingMethod::SELF_SERVICE, '3階ロッカーA', 'ノートPC1');
        $this->bus()->dispatch(new LendAsset($loanedAsset->id, $employee->id, $employee->id));

        $notLoanedAsset = $this->registerAsset($manager, AssetLendingMethod::SELF_SERVICE, '3階ロッカーA', 'ノートPC2');

        $response = $this->actingAs($employee)->postJson('/api/assets/bulk', [
            'operation' => 'self_return',
            'asset_ids' => [$loanedAsset->id, $notLoanedAsset->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('succeeded_count', 1);
        $response->assertJsonPath('failed_count', 1);

        $results = $response->json('results');
        $this->assertTrue(collect($results)->firstWhere('asset_id', $loanedAsset->id)['success']);
        $this->assertFalse(collect($results)->firstWhere('asset_id', $notLoanedAsset->id)['success']);
    }

    public function test_bulk_backoffice_lend_requires_asset_manage_permission(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $employee = User::factory()->create();
        $asset = $this->registerAsset($manager, AssetLendingMethod::BACKOFFICE);

        $this->actingAs($employee)->postJson('/api/assets/bulk', [
            'operation' => 'backoffice_lend',
            'asset_ids' => [$asset->id],
            'borrower_user_id' => $employee->id,
        ])->assertForbidden();
    }

    // --- ユーザー貸与品一覧 ---

    public function test_user_can_view_their_own_asset_loans(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager, AssetLendingMethod::SELF_SERVICE, '3階ロッカーA');
        $employee = User::factory()->create();
        $this->bus()->dispatch(new LendAsset($asset->id, $employee->id, $employee->id));

        $response = $this->actingAs($employee)->getJson("/api/users/{$employee->id}/asset-loans");

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_user_cannot_view_someone_elses_asset_loans_without_permission(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager, AssetLendingMethod::SELF_SERVICE, '3階ロッカーA');
        $employee = User::factory()->create();
        $this->bus()->dispatch(new LendAsset($asset->id, $employee->id, $employee->id));

        $otherEmployee = User::factory()->create();

        $this->actingAs($otherEmployee)->getJson("/api/users/{$employee->id}/asset-loans")->assertForbidden();
    }

    // --- その他業務操作: asset.manage必須 ---

    public function test_user_without_asset_manage_permission_cannot_start_repair(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager);
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson("/api/assets/{$asset->id}/repair/start", [])->assertForbidden();
    }

    public function test_user_with_asset_manage_permission_can_start_and_complete_repair(): void
    {
        $manager = User::factory()->create();
        $this->grantAssetManage($manager);
        $asset = $this->registerAsset($manager);

        $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/repair/start", [])
            ->assertOk()->assertJsonPath('lending_status', 'repair');

        $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/repair/complete", [])
            ->assertOk()->assertJsonPath('lending_status', 'available');
    }
}
