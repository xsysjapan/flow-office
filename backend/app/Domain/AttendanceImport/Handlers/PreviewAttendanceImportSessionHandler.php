<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\AttendanceImport\Commands\PreviewAttendanceImportSession;
use App\Domain\AttendanceImport\Events\AttendanceImportSessionPreviewed;
use App\Domain\AttendanceImport\Services\AttendanceDifferenceDetector;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\AttendanceImportItem;
use App\Models\AttendanceImportSession;
use App\Models\ImportItemStatus;
use App\Models\ImportSessionStatus;

/**
 * UC-R001手順6: 報告書候補と既存データを比較し、日別の差異を計算する
 * (docs/26-usecases-monthly-import.md「差異検出」)。
 *
 * @implements CommandHandler<PreviewAttendanceImportSession>
 */
class PreviewAttendanceImportSessionHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceDifferenceDetector $detector,
    ) {}

    public function handle(Command $command): AttendanceImportSession
    {
        assert($command instanceof PreviewAttendanceImportSession);

        $session = AttendanceImportSession::query()->with('items')->findOrFail($command->sessionId);

        $blockingCount = 0;
        foreach ($session->items as $item) {
            $result = $this->detector->detect($session->user_id, $session->target_month, $item->proposed_data_json);

            $item->existing_data_json = $result['existing'];
            $item->differences_json = $result['differences'];
            $item->status = ImportItemStatus::PENDING_REVIEW;
            $item->save();

            if ($item->hasBlockingDifferences()) {
                $blockingCount++;
            }
        }

        $proposedDates = $session->items->pluck('work_date')->map(fn ($date) => $date->toDateString())->all();
        $missingDates = $this->detector->findDatesMissingFromReport($session->user_id, $session->target_month, $proposedDates);

        foreach ($missingDates as $missingDate) {
            $item = AttendanceImportItem::query()->create([
                'import_session_id' => $session->id,
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

        $this->eventStore->append(
            aggregateType: 'attendance_import_session',
            aggregateId: (string) $session->id,
            event: new AttendanceImportSessionPreviewed(
                sessionId: $session->id,
                itemCount: $session->items()->count(),
                itemsWithBlockingDifferences: $blockingCount,
            ),
        );

        return $session->fresh('items');
    }
}
