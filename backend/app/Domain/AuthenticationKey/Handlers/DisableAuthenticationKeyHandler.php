<?php

namespace App\Domain\AuthenticationKey\Handlers;

use App\Domain\AuthenticationKey\Aggregates\AuthenticationKeyAggregate;
use App\Domain\AuthenticationKey\Commands\DisableAuthenticationKey;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\AuthenticationKey;

/**
 * @implements CommandHandler<DisableAuthenticationKey>
 */
class DisableAuthenticationKeyHandler implements CommandHandler
{
    public function handle(Command $command): AuthenticationKey
    {
        assert($command instanceof DisableAuthenticationKey);

        $key = AuthenticationKey::query()->findOrFail($command->authenticationKeyId);

        AuthenticationKeyAggregate::retrieve($key->id)
            ->disable($command->disabledByUserId, now()->format('Y-m-d H:i:s'))
            ->persist();

        return $key->refresh();
    }
}
