<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\AttendanceDraftDayApplier;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use Illuminate\Support\Collection;

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
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');

            $applier = new AttendanceDraftDayApplier;
            $result = $applier->apply(
                $client,
                $arguments['draft_id'],
                $mcpUserId,
                $arguments['expected_version'],
                $arguments['days'],
                $arguments['idempotency_key'] ?? null,
                $mcpUserId,
            );

            $rejected = Collection::make($result['results'])->contains(fn ($r) => $r['status'] === 'REJECTED');

            return [
                'status' => $rejected ? 'PARTIALLY_ACCEPTED' : 'ACCEPTED',
                'draft' => $result['draft']->toArray(),
                'results' => $result['results'],
            ];
        });
    }
}
