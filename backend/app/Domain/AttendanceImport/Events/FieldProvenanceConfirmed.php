<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class FieldProvenanceConfirmed implements DomainEvent
{
    public function __construct(
        public readonly int $fieldProvenanceId,
        public readonly int $confirmedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'field_provenance.confirmed';
    }

    public function payload(): array
    {
        return [
            'field_provenance_id' => $this->fieldProvenanceId,
            'confirmed_by_user_id' => $this->confirmedByUserId,
        ];
    }
}
