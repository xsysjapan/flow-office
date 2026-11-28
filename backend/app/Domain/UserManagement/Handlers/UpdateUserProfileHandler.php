<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\UpdateUserProfile;
use App\Domain\UserManagement\Services\FieldAuthorityService;
use App\Domain\UserManagement\Services\UserProfileGuard;
use App\Models\User;

/** @implements CommandHandler<UpdateUserProfile> */
class UpdateUserProfileHandler implements CommandHandler
{
    public function __construct(private FieldAuthorityService $authorities, private UserProfileGuard $guard) {}

    public function handle(Command $command): User
    {
        assert($command instanceof UpdateUserProfile);
        $user = User::query()->findOrFail($command->userId);
        $this->authorities->assertLocallyEditable(array_keys($command->changes));
        $this->guard->assertNotDisablingLastSystemAdministrator($user, $command->changes);
        $before = $user->only(array_keys($command->changes));
        $after = array_replace($before, $command->changes);
        UserAggregate::retrieve($user->id)->updateProfile($before, $after, $command->changedByUserId)->persist();

        return $user->refresh();
    }
}
