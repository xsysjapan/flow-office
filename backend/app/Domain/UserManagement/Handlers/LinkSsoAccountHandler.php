<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\LinkSsoAccount;
use App\Models\ExternalIdentity;
use App\Models\User;

/**
 * @implements CommandHandler<LinkSsoAccount>
 */
class LinkSsoAccountHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof LinkSsoAccount);

        $user = User::query()->findOrFail($command->userId);

        if ($user->entra_user_id === $command->entraUserId) {
            return $user;
        }

        if ($user->entra_user_id !== null) {
            throw new DomainRuleException('このアカウントは既に別のMicrosoft 365アカウントと連携済みです。');
        }

        $conflict = ExternalIdentity::query()->where('provider', 'MICROSOFT_ENTRA')->where('external_subject_id', $command->entraUserId)->where('status', 'active')->exists()
            || User::query()->where('entra_user_id', $command->entraUserId)->exists();

        if ($conflict) {
            throw new DomainRuleException('このMicrosoft 365アカウントは既に他のユーザーと連携済みのため、連携できません。');
        }

        UserAggregate::retrieve($user->id)->linkSsoAccount($command->entraUserId)->persist();

        return $user->refresh();
    }
}
