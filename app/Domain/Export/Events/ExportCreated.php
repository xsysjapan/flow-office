<?php

namespace App\Domain\Export\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * export.created (UC-E001/UC-E002: 出力履歴を記録する)
 */
class ExportCreated implements DomainEvent
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $exportType,
        public readonly array $params,
        public readonly int $requestedByUserId,
        public readonly int $rowCount,
    ) {}

    public function eventType(): string
    {
        return 'export.created';
    }

    public function payload(): array
    {
        return [
            'export_type' => $this->exportType,
            'params' => $this->params,
            'requested_by_user_id' => $this->requestedByUserId,
            'row_count' => $this->rowCount,
        ];
    }
}
