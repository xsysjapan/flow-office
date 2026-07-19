<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\FieldProvenance;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;
use RuntimeException;

class SubmitMonthlyAttendanceTool implements Tool
{
    public function name(): string
    {
        return 'submit_monthly_attendance';
    }

    public function description(): string
    {
        return '月次勤怠を申請する。ユーザーの明示的な指示があった場合にのみ呼び出すこと'.
            '(docs/26「月次申請」。ユーザーの指示なしに呼ばない)。'.
            '未確認のAI推定値が残っている場合はmcp/側で拒否し、backend/へは送信しない。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'draft_id' => ['type' => 'integer'],
                'approver_user_id' => ['type' => 'integer'],
            ],
            'required' => ['draft_id', 'approver_user_id'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:submit'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(function () use ($arguments, $client) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');
            $draft = MonthlyAttendanceDraft::query()->where('user_id', $mcpUserId)->findOrFail($arguments['draft_id']);

            if ($draft->status === MonthlyDraftStatus::SUBMITTED) {
                throw new RuntimeException('この下書きは既に月次申請済みです。');
            }

            $unconfirmed = FieldProvenance::latestForEntity(FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT, $draft->id)
                ->filter(fn (FieldProvenance $provenance) => $provenance->isImportantAndUnconfirmed());

            if ($unconfirmed->isNotEmpty()) {
                throw new RuntimeException(
                    'AI推定値のうちユーザー未確認の重要項目が残っているため申請できません(AI_INFERRED_VALUE_UNCONFIRMED): '
                    .$unconfirmed->pluck('field_name')->implode(', ')
                );
            }

            if ($draft->status !== MonthlyDraftStatus::READY_TO_SUBMIT) {
                throw new RuntimeException('未解決の問題が残っているため申請できません。先に検証を通過させてください。');
            }

            $attendanceMonth = $client->post("/attendance/months/{$draft->target_month}/submit", [
                'approver_user_id' => $arguments['approver_user_id'],
            ]);

            $draft->status = MonthlyDraftStatus::SUBMITTED;
            $draft->submitted_at = now();
            $draft->save();

            return ['draft' => $draft->toArray(), 'attendance_month' => $attendanceMonth];
        });
    }
}
