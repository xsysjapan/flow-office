<?php

namespace App\Models;

/**
 * application_integrations.client_type。docs/25-usecases-integrations-mcp.md参照。
 */
final class IntegrationClientType
{
    public const API_CLIENT = 'api_client';

    public const MCP_CLIENT = 'mcp_client';

    public const AI_APPLICATION = 'ai_application';

    public const EXTERNAL_APPLICATION = 'external_application';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [self::API_CLIENT, self::MCP_CLIENT, self::AI_APPLICATION, self::EXTERNAL_APPLICATION];
    }
}
