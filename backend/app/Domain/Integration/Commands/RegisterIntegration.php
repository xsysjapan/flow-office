<?php

namespace App\Domain\Integration\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-I001: 個人API・MCP連携を登録する(docs/25-usecases-integrations-mcp.md)。
 */
class RegisterIntegration implements Command
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly string $ownerUserId,
        public readonly string $clientType,
        public readonly string $clientName,
        public readonly ?string $purpose,
        public readonly array $scopes,
        public readonly string $registeredByUserId,
    ) {}
}
