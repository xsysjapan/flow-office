<?php

use App\Domain\AccessControl\Commands\ChangeRolePermissions;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 権限`read_model.rebuild`(ReadModelの作り直し。global)を追加し、システム管理者ロールへ付ける
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md 論点7)。
 *
 * `permissions`・`permission_scope_types`は参照マスタ(`AccessControlCatalog`の同期と同じ内容)で直接書く。
 * ロールの権限(`permission_role`はReadModel)は直接書かず、`ChangeRolePermissions`(現在の集合+この権限の全置換)を
 * CommandBusで発行して`role.permissions_changed`を記録する(ReadModelの作り直しで消えないように)。
 * 管理者ロールが無い、または権限がまだ1件も設定されていない環境(新規環境)は何もしない(シーダーが全権限を付ける)。
 * 既に持っていれば何もしない。
 */
return new class extends Migration
{
    private const CODE = 'read_model.rebuild';

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->updateOrInsert(['code' => self::CODE], [
            'resource' => 'read_model',
            'action' => 'rebuild',
            'description' => 'データの作り直し',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $permissionId = (int) DB::table('permissions')->where('code', self::CODE)->value('id');
        DB::table('permission_scope_types')->insertOrIgnore(['permission_id' => $permissionId, 'scope_type' => 'global']);

        $adminRoleId = DB::table('roles')->where('code', 'admin')->where('status', 'active')->value('id');
        if ($adminRoleId === null) {
            return;
        }

        $current = DB::table('permission_role')->where('role_id', $adminRoleId)->pluck('permission_id')->map(fn ($id) => (int) $id)->all();
        if ($current === [] || in_array($permissionId, $current, true)) {
            return;
        }

        app(CommandBus::class)->dispatch(new ChangeRolePermissions((int) $adminRoleId, [...$current, $permissionId], null));
    }

    public function down(): void
    {
        // 付与済みの権限・イベントは戻さない(`role.permissions_changed`は履歴として残す)。
    }
};
