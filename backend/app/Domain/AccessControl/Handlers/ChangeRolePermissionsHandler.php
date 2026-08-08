<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAggregate;
use App\Domain\AccessControl\Commands\ChangeRolePermissions;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<ChangeRolePermissions> */ class ChangeRolePermissionsHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof ChangeRolePermissions);
        $role = DB::table('roles')->where('id', $c->roleId)->where('status', 'active')->first();
        if (! $role) {
            throw new DomainRuleException('有効なRoleが存在しません。');
        } if (DB::table('permissions')->whereIn('id', $c->permissionIds)->count() !== count(array_unique($c->permissionIds))) {
            throw new DomainRuleException('存在しないPermissionが含まれています。');
        } $userManageId = DB::table('permissions')->where('code', 'user.manage')->value('id');
        if ($role->code === Role::ADMIN && $userManageId !== null && ! in_array((int) $userManageId, $c->permissionIds, true)) {
            throw new DomainRuleException('システム管理者Roleからuser.manageは削除できません。');
        } $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:'.$role->code)->toString();
        RoleAggregate::retrieve($id)->changePermissions($c->roleId, array_values(array_unique($c->permissionIds)), $c->actorUserId)->persist();

        return (string) $c->roleId;
    }
}
