<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class BulkUpdateAttendanceDaysTool implements Tool
{
    public function name(): string
    {
        return 'bulk_update_attendance_days';
    }

    public function description(): string
    {
        return '月次勤怠下書きへ複数日分の勤務候補をまとめて反映する(docs/26「一括更新API」)。'.
            '楽観ロック(expected_version)・冪等性(idempotency_key)に対応する。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'draft_id' => ['type' => 'integer'],
                'expected_version' => ['type' => 'integer'],
                'days' => ['type' => 'array', 'items' => DaySchema::schema()],
                'idempotency_key' => ['type' => 'string'],
            ],
            'required' => ['draft_id', 'expected_version', 'days'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:update'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(function () use ($arguments, $client) {
            $headers = isset($arguments['idempotency_key'])
                ? ['Idempotency-Key' => $arguments['idempotency_key']]
                : [];

            return $client->put(
                "/attendance/monthly-drafts/{$arguments['draft_id']}/days",
                ['expected_version' => $arguments['expected_version'], 'days' => $arguments['days']],
                $headers,
            );
        });
    }
}
