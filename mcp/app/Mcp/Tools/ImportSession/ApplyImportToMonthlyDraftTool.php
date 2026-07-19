<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\AttendanceDraftDayApplier;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\AttendanceImportSession;
use App\Models\FieldSourceType;
use App\Models\ImportItemStatus;
use App\Models\ImportSessionStatus;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;
use RuntimeException;

class ApplyImportToMonthlyDraftTool implements Tool
{
    public function name(): string
    {
        return 'apply_import_to_monthly_draft';
    }

    public function description(): string
    {
        return '差異のない日を一括で月次勤怠下書きへ反映する。差異のある日も反映されるが、'.
            'ユーザー未確認のAI推定値として残る(docs/26「不明点の確認」)。'.
            '反映は下書き段階のbackend/呼び出し(日次編集API)としてのみ行われ、正式申請ではない。';
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
        return ToolResult::run(function () use ($arguments, $client) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');
            $session = AttendanceImportSession::query()->where('user_id', $mcpUserId)->with('items')->findOrFail($arguments['session_id']);

            if ($session->status !== ImportSessionStatus::PREVIEWED) {
                throw new RuntimeException('先にpreview_attendance_importで差異検出を行ってください。');
            }

            if (($arguments['draft_id'] ?? null) !== null) {
                $draft = MonthlyAttendanceDraft::query()->where('user_id', $mcpUserId)->findOrFail($arguments['draft_id']);
            } else {
                $draft = MonthlyAttendanceDraft::query()->create([
                    'user_id' => $mcpUserId,
                    'target_month' => $session->target_month,
                    'status' => MonthlyDraftStatus::DRAFT,
                    'version' => 1,
                    'source_type' => FieldSourceType::SOURCE_DOCUMENT,
                    'source_reference' => (string) $session->id,
                    'created_by_user_id' => $mcpUserId,
                ]);
            }

            $days = [];
            foreach ($session->items as $item) {
                if ($item->status === ImportItemStatus::EXCLUDED || ($item->proposed_data_json['note'] ?? null) === 'MISSING_FROM_REPORT') {
                    continue;
                }
                if (($item->proposed_data_json['startTime'] ?? null) === null) {
                    continue;
                }

                $days[] = [
                    ...$item->proposed_data_json,
                    'source' => $item->hasAnyDifferences() ? FieldSourceType::AI_INFERRED : FieldSourceType::USER_CONFIRMED,
                ];
                $item->status = ImportItemStatus::CONFIRMED;
                $item->save();
            }

            $results = [];
            if ($days !== []) {
                $applier = new AttendanceDraftDayApplier;
                $update = $applier->apply($client, $draft->id, $mcpUserId, $draft->version, $days, null, $mcpUserId);
                $draft = $update['draft'];
                $results = $update['results'];
            }

            $session->status = ImportSessionStatus::APPLIED;
            $session->monthly_attendance_draft_id = $draft->id;
            $session->save();

            return [
                'session' => $session->toArray(),
                'draft' => $draft->toArray(),
                'results' => $results,
            ];
        });
    }
}
