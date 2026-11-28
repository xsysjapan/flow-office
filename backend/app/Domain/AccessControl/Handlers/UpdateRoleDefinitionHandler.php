<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAggregate;
use App\Domain\AccessControl\Commands\UpdateRoleDefinition;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * ロール詳細のまとめて保存。名前・説明・状態・Permission・Featureのうち送られたものを検査し、ロールの集約に
 * 今と同じイベントを記録する。Permission・Featureの変更は「保存の前か後のどちらかでロールが有効」なら可。
 * 記録の順序は、有効にする変更を最初、無効にする変更を最後にする。拒否は項目のキー付きの422。
 * (docs/changesets/20261128-single-command-entrypoints/spec.md 論点2)
 *
 * @implements CommandHandler<UpdateRoleDefinition>
 */
class UpdateRoleDefinitionHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof UpdateRoleDefinition);
        $role = DB::table('roles')->where('id', $c->roleId)->lockForUpdate()->first();
        if (! $role) {
            throw new DomainRuleException('Roleが存在しません。');
        }
        $changes = $c->changes;

        $hasBasic = array_key_exists('name', $changes) || array_key_exists('description', $changes) || array_key_exists('status', $changes);
        $statusBefore = $role->status;
        $statusAfter = $changes['status'] ?? $statusBefore;
        $permissionIds = array_key_exists('permission_ids', $changes) ? array_values(array_unique($changes['permission_ids'])) : null;
        $featureIds = array_key_exists('feature_ids', $changes) ? array_values(array_unique($changes['feature_ids'])) : null;

        $errors = [];
        if ($hasBasic && $role->is_system && $statusAfter !== 'active') {
            $errors[array_key_exists('status', $changes) ? 'status' : (array_key_exists('name', $changes) ? 'name' : 'description')] = ['システムRoleは廃止できません。'];
        }
        $activeBeforeOrAfter = $statusBefore === 'active' || $statusAfter === 'active';
        if ($permissionIds !== null) {
            if (! $activeBeforeOrAfter) {
                $errors['permission_ids'] = ['有効なRoleが存在しません。'];
            } elseif (DB::table('permissions')->whereIn('id', $permissionIds)->count() !== count($permissionIds)) {
                $errors['permission_ids'] = ['存在しないPermissionが含まれています。'];
            } else {
                $userManageId = DB::table('permissions')->where('code', 'user.manage')->value('id');
                if ($role->code === Role::ADMIN && $userManageId !== null && ! in_array((int) $userManageId, $permissionIds, true)) {
                    $errors['permission_ids'] = ['システム管理者Roleからuser.manageは削除できません。'];
                }
            }
        }
        if ($featureIds !== null) {
            if (! $activeBeforeOrAfter) {
                $errors['feature_ids'] = ['有効なRoleが存在しません。'];
            } elseif (DB::table('features')->whereIn('id', $featureIds)->count() !== count($featureIds)) {
                $errors['feature_ids'] = ['存在しないFeatureが含まれています。'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $aggregate = RoleAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:'.$role->code)->toString());
        $recordBasic = fn () => $aggregate->update(
            $c->roleId,
            $changes['name'] ?? $role->name,
            array_key_exists('description', $changes) ? $changes['description'] : $role->description,
            $statusAfter,
            $c->actorUserId,
        );
        $disabling = $statusBefore === 'active' && $statusAfter !== 'active';

        // 有効にする(または状態を変えない)変更は最初に、無効にする変更は最後に記録する。
        if ($hasBasic && ! $disabling) {
            $recordBasic();
        }
        if ($permissionIds !== null) {
            $aggregate->changePermissions($c->roleId, $permissionIds, $c->actorUserId);
        }
        if ($featureIds !== null) {
            $aggregate->changeFeatures($c->roleId, $featureIds, $c->actorUserId);
        }
        if ($hasBasic && $disabling) {
            $recordBasic();
        }
        $aggregate->persist();

        return (string) $c->roleId;
    }
}
