<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\UpdateUserProfile;
use App\Domain\UserManagement\Services\FieldAuthorityService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<UpdateUserProfile> */
class UpdateUserProfileHandler implements CommandHandler
{
    public function __construct(private FieldAuthorityService $authorities) {}

    public function handle(Command $command): User
    {
        assert($command instanceof UpdateUserProfile);
        $user = User::query()->with('roles')->findOrFail($command->userId);
        $this->authorities->assertLocallyEditable(array_keys($command->changes));
        if (array_key_exists('account_status', $command->changes) && $command->changes['account_status'] !== 'active' && $user->roles->contains('code', Role::ADMIN)) {
            $activeAdminCount = DB::table('users')->join('role_user', 'users.id', '=', 'role_user.user_id')->join('roles', 'role_user.role_id', '=', 'roles.id')->where('roles.code', Role::ADMIN)->where('users.account_status', 'active')->count();
            if ($user->account_status === 'active' && $activeAdminCount <= 1) {
                throw new DomainRuleException('最後のシステム管理者は無効化できません。');
            }
        } $before = $user->only(array_keys($command->changes));
        $after = array_replace($before, $command->changes);
        UserAggregate::retrieve($user->id)->updateProfile($before, $after, $command->changedByUserId)->persist();

        return $user->refresh();
    }
}
