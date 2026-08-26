<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\AccessControlCatalog;
use Illuminate\Support\Facades\DB;

/**
 * `AccessControlCatalog`に定義されたFeature・Permissionを`features`・`permissions`・
 * `permission_scope_types`へ反映する。`updateOrInsert`のみで構成した追加専用の同期であり、
 * 既存のRole割当・Feature割当・Permission割当(グループ・ユーザーへの割当や、管理画面から
 * 個別に変更されたRoleのPermission構成)には触れない。デプロイのたびに実行しても安全
 * (`deploy/scripts/activate-release.sh`から自動実行する)。
 */
class SyncAccessControlCatalog
{
    public function sync(): void
    {
        $now = now();

        foreach (AccessControlCatalog::FEATURE_PARENTS as $code => [$name, $order]) {
            DB::table('features')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'parent_feature_id' => null, 'display_order' => $order, 'is_selectable' => false, 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
            );
        }

        foreach (AccessControlCatalog::FEATURE_CHILDREN as $code => [$name, $parent, $order]) {
            DB::table('features')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'parent_feature_id' => DB::table('features')->where('code', $parent)->value('id'), 'display_order' => $order, 'is_selectable' => true, 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
            );
        }

        foreach (AccessControlCatalog::PERMISSIONS as $code => $definition) {
            [$resource, $action] = explode('.', $code, 2);
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $definition['name'], 'updated_at' => $now, 'created_at' => $now],
            );
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            DB::table('permission_scope_types')->where('permission_id', $permissionId)->delete();
            DB::table('permission_scope_types')->insert(array_map(
                fn (string $scope) => ['permission_id' => $permissionId, 'scope_type' => $scope],
                $definition['scopes'],
            ));
        }
    }
}
