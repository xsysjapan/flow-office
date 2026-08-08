<?php

namespace App\Application\UserManagement\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAssignmentAggregate;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Aggregates\UserMembershipAggregate;
use App\Domain\UserManagement\Commands\CreateUser;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<CreateUser> */
final class CreateUserHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof CreateUser);

        if (User::query()->where('email', $command->attributes['email'])->exists()) {
            throw new DomainRuleException('メールアドレスは既に使用されています。');
        }
        if (($command->attributes['employee_number'] ?? null) !== null
            && User::query()->where('employee_number', $command->attributes['employee_number'])->exists()) {
            throw new DomainRuleException('社員番号は既に使用されています。');
        }

        UserAggregate::retrieve($command->userId)
            ->createManually($command->attributes, $command->createdByUserId)
            ->persist();

        $allUsers = DB::table('groups')->where('code', 'ALL_USERS')->where('status', 'active')->first();
        if ($allUsers !== null) {
            UserMembershipAggregate::retrieve(UserManagementStreamId::for('user-membership', $command->userId))
                ->add($command->userId, $allUsers->id, 'member', false, $command->createdByUserId)
                ->persist();
        }

        $employeeRole = Role::query()->where('code', Role::EMPLOYEE)->where('status', 'active')->first();
        if ($employeeRole !== null) {
            UserAggregate::retrieve($command->userId)
                ->changeRoles([], [$employeeRole->code], $command->createdByUserId)
                ->persist();
            $assignmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'legacy-role-assignment:'.$command->userId.':'.$employeeRole->id)->toString();
            RoleAssignmentAggregate::retrieve($assignmentId)
                ->create('user', $command->userId, $employeeRole->id, 'global', null, false, null, null, $command->createdByUserId)
                ->persist();
        }

        return User::query()->with('roles')->findOrFail($command->userId);
    }
}
