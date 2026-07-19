<?php

namespace App\Mcp\Contracts;

use App\Mcp\Support\BackendApiClient;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * MCPのtools/list向けJSON Schema(inputSchema)。
     */
    public function inputSchema(): array;

    /**
     * 必要なbackendスコープ(docs/25 UC-I002)。ドキュメント目的のみで、実際の可否判定は
     * backend側のability検証にそのまま従う(ここでは事前拒否しない)。
     */
    public function requiredScopes(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function handle(array $arguments, BackendApiClient $client): array;
}
