<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\UpdateUserProfile;
use App\Domain\UserManagement\Services\FieldAuthorityService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<UpdateUserProfile> */
class UpdateUserProfileHandler implements CommandHandler
{
    public function __construct(private FieldAuthorityService $authorities) {}

    public function handle(Command $command): User
    {
        assert($command instanceof UpdateUserProfile);
        $user = User::query()->findOrFail($command->userId);
        $this->authorities->assertLocallyEditable(array_keys($command->changes));
        $adminGroupId = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id');
        $isSystemAdministrator = $adminGroupId !== null
            && DB::table('memberships')->where('user_id', $user->id)->where('group_id', $adminGroupId)->exists();
        if (array_key_exists('account_status', $command->changes) && $command->changes['account_status'] !== 'active' && $isSystemAdministrator) {
            $activeAdminCount = DB::table('users')
                ->join('memberships', 'users.id', '=', 'memberships.user_id')
                ->where('memberships.group_id', $adminGroupId)
                ->where('users.account_status', 'active')
                ->count();
            if ($user->account_status === 'active' && $activeAdminCount <= 1) {
                throw new DomainRuleException('最後のシステム管理者は無効化できません。');
            }
        } $before = $user->only(array_keys($command->changes));
        $after = array_replace($before, $command->changes);
        UserAggregate::retrieve($user->id)->updateProfile($before, $after, $command->changedByUserId)->persist();

        return $user->refresh();
    }
}
