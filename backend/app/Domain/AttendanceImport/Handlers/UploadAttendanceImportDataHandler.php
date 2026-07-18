<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\AttendanceImport\Commands\UploadAttendanceImportData;
use App\Domain\AttendanceImport\Events\AttendanceImportDataUploaded;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceImportSession;
use App\Models\ImportItemStatus;
use App\Models\ImportSessionStatus;

/**
 * 作業報告書から抽出した構造化データ(日別の勤務候補)を受け取る(docs/26「Claudeによる構造化」)。
 * ファイル解析自体はClaude側で完結しており、ここでは構造化済みJSONの受け入れのみ行う。
 *
 * @implements CommandHandler<UploadAttendanceImportData>
 */
class UploadAttendanceImportDataHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): AttendanceImportSession
    {
        assert($command instanceof UploadAttendanceImportData);

        $session = AttendanceImportSession::query()->findOrFail($command->sessionId);

        if ($session->status !== ImportSessionStatus::CREATED) {
            throw new DomainRuleException('このインポートセッションは既にデータを受け付け済みです。');
        }

        foreach ($command->days as $day) {
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

        $this->eventStore->append(
            aggregateType: 'attendance_import_session',
            aggregateId: (string) $session->id,
            event: new AttendanceImportDataUploaded(
                sessionId: $session->id,
                itemCount: count($command->days),
            ),
        );

        return $session->load('items');
    }
}
