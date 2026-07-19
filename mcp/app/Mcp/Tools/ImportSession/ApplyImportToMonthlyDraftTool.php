<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class ApplyImportToMonthlyDraftTool implements Tool
{
    public function name(): string
    {
        return 'apply_import_to_monthly_draft';
    }

    public function description(): string
    {
        return '差異のない日を一括で月次勤怠下書きへ反映する。差異のある日も反映されるが、'.
            'ユーザー未確認のAI推定値として残る(docs/26「不明点の確認」)。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => ['type' => 'integer'],
                'draft_id' => ['type' => 'integer'],
            ],
            'required' => ['session_id'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['report:self:import'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->post("/attendance/import-sessions/{$arguments['session_id']}/apply", [
            'draft_id' => $arguments['draft_id'] ?? null,
        ]));
    }
}
