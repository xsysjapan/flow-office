<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\AttendanceImportSession;
use App\Models\ImportItemStatus;
use App\Models\ImportSessionStatus;
use RuntimeException;

class UploadAttendanceImportDataTool implements Tool
{
    public function name(): string
    {
        return 'upload_attendance_import_data';
    }

    public function description(): string
    {
        return 'Claudeが作業報告書から抽出した日別勤務候補(構造化データ)をインポートセッションへ送信する'.
            '(docs/26 UC-R001手順4)。ファイルそのものは送らない。mcp/自身のDBに保持する。';
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
        return ToolResult::run(function () use ($arguments) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');
            $session = AttendanceImportSession::query()->where('user_id', $mcpUserId)->findOrFail($arguments['session_id']);

            if ($session->status !== ImportSessionStatus::CREATED) {
                throw new RuntimeException('このインポートセッションは既にデータを受け付け済みです。');
            }

            foreach ($arguments['days'] as $day) {
                $session->items()->create([
                    'work_date' => $day['date'],
                    'proposed_data_json' => $day,
                    'confidence' => $day['confidence'] ?? null,
                    'status' => ImportItemStatus::PENDING_REVIEW,
                    'source_reference_json' => $day['sourceReferences'] ?? null,
                ]);
            }

            $session->status = ImportSessionStatus::PREVIEWING;
            $session->save();

            return $session->load('items')->toArray();
        });
    }
}
