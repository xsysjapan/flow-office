<?php

namespace App\Application\UserManagement\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAssignmentAggregate;
use App\Domain\AccessControl\Services\PrivilegeAssignmentPolicy;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Aggregates\UserMembershipAggregate;
use App\Domain\UserManagement\Commands\AssignUserRoles;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * @implements CommandHandler<AssignUserRoles>
 */
class AssignUserRolesHandler implements CommandHandler
{
    public function __construct(private PrivilegeAssignmentPolicy $privilegePolicy) {}

    public function handle(Command $command): User
    {
        assert($command instanceof AssignUserRoles);

        $user = User::query()->with('roles')->findOrFail($command->userId);
        $previousRoleCodes = $user->roles->pluck('code')->all();

        $roles = Role::query()->whereIn('code', $command->roleCodes)->get();
        if ($roles->count() !== count(array_unique($command->roleCodes))) {
            throw new InvalidArgumentException('存在しないロールコードが指定されました。');
        }

        foreach ($roles->whereNotIn('code', $previousRoleCodes) as $role) {
            $this->privilegePolicy->assertSelfAssignmentAllowed(
                $command->changedByUserId,
                'user',
                $user->id,
                $role->id,
            );
        }

        if (in_array(Role::ADMIN, $previousRoleCodes, true) && ! in_array(Role::ADMIN, $command->roleCodes, true)) {
            $adminCount = DB::table('role_user')->join('roles', 'role_user.role_id', '=', 'roles.id')->join('users', 'role_user.user_id', '=', 'users.id')->where('roles.code', Role::ADMIN)->whereIn('users.account_status', ['active', 'leave'])->count();
            if (in_array($user->account_status, ['active', 'leave'], true) && $adminCount <= 1) {
                throw new DomainRuleException('最後のシステム管理者からadmin Roleを外すことはできません。');
            }
        }

        UserAggregate::retrieve($user->id)
            ->changeRoles($previousRoleCodes, $roles->pluck('code')->all(), $command->changedByUserId)
            ->persist();

        foreach ($roles as $role) {
            $assignmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'legacy-role-assignment:'.$user->id.':'.$role->id)->toString();
            if (! DB::table('role_assignments')->where('id', $assignmentId)->where('status', 'active')->exists()) {
                RoleAssignmentAggregate::retrieve($assignmentId)->create('user', $user->id, $role->id, 'global', null, false, null, null, $command->changedByUserId)->persist();
            }
        }
        $removedRoleIds = Role::query()->whereIn('code', array_diff($previousRoleCodes, $command->roleCodes))->pluck('id');
        foreach ($removedRoleIds as $roleId) {
            $assignmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'legacy-role-assignment:'.$user->id.':'.$roleId)->toString();
            if (DB::table('role_assignments')->where('id', $assignmentId)->where('status', 'active')->exists()) {
                RoleAssignmentAggregate::retrieve($assignmentId)->remove($command->changedByUserId)->persist();
            }
        }

        $adminGroup = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->first();
        if ($adminGroup) {
            $isMember = DB::table('memberships')->where('user_id', $user->id)->where('group_id', $adminGroup->id)->exists();
            $membershipAggregate = UserMembershipAggregate::retrieve(UserManagementStreamId::for('user-membership', $user->id));
            if (in_array(Role::ADMIN, $command->roleCodes, true) && ! $isMember) {
                $membershipAggregate->add($user->id, $adminGroup->id, 'member', false, $command->changedByUserId)->persist();
            }
            if (! in_array(Role::ADMIN, $command->roleCodes, true) && $isMember) {
                $membershipAggregate->remove($user->id, $adminGroup->id, $command->changedByUserId)->persist();
            }
        }

        return User::query()->with('roles')->findOrFail($user->id);
    }
}
