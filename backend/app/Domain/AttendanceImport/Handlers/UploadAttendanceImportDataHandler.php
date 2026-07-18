<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\AttendanceImport\Commands\UploadAttendanceImportData;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceImportSession;
use App\Models\ImportItemStatus;
use App\Models\ImportSessionStatus;

/**
 * 作業報告書から抽出した構造化データ(日別の勤務候補)を受け取る(docs/26「Claudeによる構造化」)。
 * ファイル解析自体はClaude側で完結しており、ここでは構造化済みJSONの受け入れのみ行う。
 * このステップ自体は下書きに何かを確定させるものではないため、ドメインイベントは記録しない
 * (attendance_import_session.created/previewed/appliedが節目のイベント)。
 *
 * @implements CommandHandler<UploadAttendanceImportData>
 */
class UploadAttendanceImportDataHandler implements CommandHandler
{
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

        return $session->load('items');
    }
}
