<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\AttendanceImport\Commands\ApplyAttendanceImportSessionToDraft;
use App\Domain\AttendanceImport\Commands\BulkUpdateAttendanceDays;
use App\Domain\AttendanceImport\Commands\CreateMonthlyAttendanceDraft;
use App\Domain\AttendanceImport\Events\AttendanceImportSessionApplied;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceImportSession;
use App\Models\FieldSourceType;
use App\Models\ImportItemStatus;
use App\Models\ImportSessionStatus;
use App\Models\MonthlyAttendanceDraft;

/**
 * UC-R001手順8: 差異のない日は一括で下書き候補とし(field_provenanceを直ちに
 * user_confirmedにする)、差異のある日は反映するがai_inferredのまま未確認で残す
 * (docs/26「不明点の確認」)。既存のCreateMonthlyAttendanceDraft/BulkUpdateAttendanceDays
 * Handlerをそのまま再利用する。
 *
 * @implements CommandHandler<ApplyAttendanceImportSessionToDraft>
 */
class ApplyAttendanceImportSessionToDraftHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly CreateMonthlyAttendanceDraftHandler $createDraftHandler,
        private readonly BulkUpdateAttendanceDaysHandler $bulkUpdateHandler,
    ) {}

    /**
     * @return array{session: AttendanceImportSession, draft: MonthlyAttendanceDraft, results: array<int, mixed>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ApplyAttendanceImportSessionToDraft);

        $session = AttendanceImportSession::query()->with('items')->findOrFail($command->sessionId);

        if ($session->status !== ImportSessionStatus::PREVIEWED) {
            throw new DomainRuleException('先にpreview_attendance_importで差異検出を行ってください。');
        }

        if ($command->draftId !== null) {
            $draft = MonthlyAttendanceDraft::query()->findOrFail($command->draftId);
            if ($draft->user_id !== $session->user_id) {
                throw new DomainRuleException('指定された下書きはこのインポートセッションの対象社員のものではありません。');
            }
        } else {
            $draft = $this->createDraftHandler->handle(new CreateMonthlyAttendanceDraft(
                userId: $session->user_id,
                targetMonth: $session->target_month,
                sourceType: FieldSourceType::SOURCE_DOCUMENT,
                sourceReference: (string) $session->id,
                createdByUserId: $command->appliedByUserId,
            ));
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
                // 差異が1件でもある日は(severityがwarningのみであっても)AIが自己判断で
                // 確定させず、必ずai_inferredとして残しユーザー確認を要求する。差異が
                // 全く無い日(既存の実績・打刻・休暇・シフトと完全一致)のみ自動確定する。
                'source' => $item->hasAnyDifferences() ? FieldSourceType::AI_INFERRED : FieldSourceType::USER_CONFIRMED,
            ];
            $item->status = ImportItemStatus::CONFIRMED;
            $item->save();
        }

        $results = [];
        if ($days !== []) {
            $update = $this->bulkUpdateHandler->handle(new BulkUpdateAttendanceDays(
                draftId: $draft->id,
                expectedVersion: $draft->version,
                days: $days,
                updatedByUserId: $command->appliedByUserId,
            ));
            $draft = $update['draft'];
            $results = $update['results'];
        }

        $session->status = ImportSessionStatus::APPLIED;
        $session->monthly_attendance_draft_id = $draft->id;
        $session->save();

        $this->eventStore->append(
            aggregateType: 'attendance_import_session',
            aggregateId: (string) $session->id,
            event: new AttendanceImportSessionApplied(
                sessionId: $session->id,
                draftId: $draft->id,
                appliedByUserId: $command->appliedByUserId,
            ),
        );

        return ['session' => $session, 'draft' => $draft, 'results' => $results];
    }
}
