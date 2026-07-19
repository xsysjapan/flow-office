<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class UploadAttendanceImportDataTool implements Tool
{
    public function name(): string
    {
        return 'upload_attendance_import_data';
    }

    public function description(): string
    {
        return 'Claudeが作業報告書から抽出した日別勤務候補(構造化データ)をインポートセッションへ送信する'.
            '(docs/26 UC-R001手順4)。ファイルそのものは送らない。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => ['type' => 'integer'],
                'days' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'required' => ['session_id', 'days'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['report:self:import'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->post("/attendance/import-sessions/{$arguments['session_id']}/data", [
            'days' => $arguments['days'],
        ]));
    }
}
