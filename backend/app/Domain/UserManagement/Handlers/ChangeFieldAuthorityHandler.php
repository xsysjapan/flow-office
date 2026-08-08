<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Aggregates\FieldAuthorityAggregate;
use App\Domain\UserManagement\Commands\ChangeFieldAuthority;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<ChangeFieldAuthority> */ class ChangeFieldAuthorityHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof ChangeFieldAuthority);
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'field-authority-catalog')->toString();
        FieldAuthorityAggregate::retrieve($id)->change($c->fieldKey, $c->authorityType, $c->provider, $c->actorUserId)->persist();

        return $c->fieldKey;
    }
}
