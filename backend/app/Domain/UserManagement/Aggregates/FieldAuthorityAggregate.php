<?php

namespace App\Domain\UserManagement\Aggregates;

use App\Domain\UserManagement\Events\UserFieldAuthorityChanged;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

final class FieldAuthorityAggregate extends AggregateRoot
{
    public function change(string $fieldKey, string $authorityType, ?string $provider, string $actor): self
    {
        $this->recordThat(new UserFieldAuthorityChanged($fieldKey, $authorityType, $provider, $actor));

        return $this;
    }
}
