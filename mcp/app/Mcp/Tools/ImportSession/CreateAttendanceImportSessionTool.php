<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class CreateAttendanceImportSessionTool implements Tool
{
    public function name(): string
    {
        return 'create_attendance_import_session';
    }

    public function description(): string
    {
        return '作業報告書インポートセッションを作成する(docs/26 UC-R001手順3)。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_month' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$'],
                'source_file_name' => ['type' => 'string'],
                'source_file_hash' => ['type' => 'string'],
            ],
            'required' => ['target_month'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['report:self:import'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->post('/attendance/import-sessions', [
            'target_month' => $arguments['target_month'],
            'source_type' => 'work_report',
            'source_file_name' => $arguments['source_file_name'] ?? null,
            'source_file_hash' => $arguments['source_file_hash'] ?? null,
        ]));
    }
}
