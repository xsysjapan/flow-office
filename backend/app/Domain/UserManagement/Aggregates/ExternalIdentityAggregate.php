<?php

namespace App\Domain\UserManagement\Aggregates;

use App\Domain\UserManagement\Events\ExternalIdentityLinked;
use App\Domain\UserManagement\Events\ExternalIdentityUnlinked;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class ExternalIdentityAggregate extends AggregateRoot
{
    public function link(string $userId, string $provider, ?string $tenant, string $subject, ?string $code, ?string $email, string $actor): self
    {
        $this->recordThat(new ExternalIdentityLinked($userId, $provider, $tenant, $subject, $code, $email, $actor));

        return $this;
    }

    public function unlink(string $userId, int $identityId, string $provider, string $externalSubjectId, string $actor): self
    {
        $this->recordThat(new ExternalIdentityUnlinked($userId, $identityId, $provider, $externalSubjectId, $actor));

        return $this;
    }
}
