<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\AttendanceImportSession;
use App\Models\ImportItemStatus;
use App\Models\ImportSessionStatus;
use Illuminate\Support\Collection;

class PreviewAttendanceImportTool implements Tool
{
    public function name(): string
    {
        return 'preview_attendance_import';
    }

    public function description(): string
    {
        return '既存の勤怠・打刻・休暇消化・勤務予定と比較し、日別の差異を検出する(docs/26「差異検出」)。'.
            '差異計算自体はbackend/のステートレスな検証エンドポイントへ委譲し、mcp/側で重複実装しない。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['session_id' => ['type' => 'integer']],
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
        return ToolResult::run(function () use ($arguments, $client) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');
            $session = AttendanceImportSession::query()->where('user_id', $mcpUserId)->with('items')->findOrFail($arguments['session_id']);

            $days = $session->items->map(fn ($item) => $item->proposed_data_json)->values()->all();
            $checked = $days === [] ? ['items' => [], 'missing_dates' => []] : $client->post('/attendance/import-preview', [
                'target_month' => $session->target_month,
                'days' => $days,
            ]);

            $byDate = Collection::make($checked['items'] ?? [])->keyBy('work_date');
            $blockingCount = 0;

            foreach ($session->items as $item) {
                $result = $byDate->get($item->work_date->toDateString());
                $item->existing_data_json = $result['existing'] ?? null;
                $item->differences_json = $result['differences'] ?? [];
                $item->status = ImportItemStatus::PENDING_REVIEW;
                $item->save();

                if ($item->hasBlockingDifferences()) {
                    $blockingCount++;
                }
            }

            foreach ($checked['missing_dates'] ?? [] as $missingDate) {
                $item = $session->items()->create([
                    'work_date' => $missingDate,
                    'proposed_data_json' => ['date' => $missingDate, 'note' => 'MISSING_FROM_REPORT'],
                    'differences_json' => [[
                        'code' => 'MISSING_IN_REPORT',
                        'severity' => 'warning',
                        'message' => "{$missingDate}は既存の勤怠がありますが、報告書に記載がありません。",
                    ]],
                    'status' => ImportItemStatus::PENDING_REVIEW,
                ]);
                $blockingCount += $item->hasBlockingDifferences() ? 1 : 0;
            }

            $session->status = ImportSessionStatus::PREVIEWED;
            $session->save();

            return $session->fresh('items')->toArray();
        });
    }
}
