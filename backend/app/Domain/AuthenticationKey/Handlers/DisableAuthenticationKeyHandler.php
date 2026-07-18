<?php

namespace App\Domain\AuthenticationKey\Handlers;

use App\Domain\AuthenticationKey\Commands\DisableAuthenticationKey;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyDisabled;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyStatus;

/**
 * @implements CommandHandler<DisableAuthenticationKey>
 */
class DisableAuthenticationKeyHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): AuthenticationKey
    {
        assert($command instanceof DisableAuthenticationKey);

        $key = AuthenticationKey::query()->findOrFail($command->authenticationKeyId);
        $key->status = AuthenticationKeyStatus::DISABLED;
        $key->disabled_at = now();
        $key->save();

        $this->eventStore->append(
            aggregateType: 'authentication_key',
            aggregateId: (string) $key->id,
            event: new AuthenticationKeyDisabled(
                authenticationKeyId: $key->id,
                disabledByUserId: $command->disabledByUserId,
            ),
        );

        return $key;
    }
}
