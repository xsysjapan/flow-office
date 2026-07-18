<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\AttendanceImport\Commands\ValidateMonthlyAttendanceDraft;
use App\Domain\AttendanceImport\Events\MonthlyAttendanceDraftValidated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\FieldProvenance;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;
use Illuminate\Support\Collection;

/**
 * UC-R002: 月次勤怠下書きを検証する。未確認のAI推定値(重要項目)が残っている場合は
 * `needs_review`のままとし、無ければ`ready_to_submit`にする(docs/26参照)。
 *
 * @implements CommandHandler<ValidateMonthlyAttendanceDraft>
 */
class ValidateMonthlyAttendanceDraftHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    /**
     * @return array{draft: MonthlyAttendanceDraft, unconfirmedFields: array<int, string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ValidateMonthlyAttendanceDraft);

        $draft = MonthlyAttendanceDraft::query()->findOrFail($command->draftId);

        $unconfirmed = $this->latestFieldProvenances($draft->id)
            ->filter(fn (FieldProvenance $provenance) => $provenance->isImportantAndUnconfirmed());

        $draft->status = $unconfirmed->isNotEmpty() ? MonthlyDraftStatus::NEEDS_REVIEW : MonthlyDraftStatus::READY_TO_SUBMIT;
        $draft->save();

        $this->eventStore->append(
            aggregateType: 'monthly_attendance_draft',
            aggregateId: (string) $draft->id,
            event: new MonthlyAttendanceDraftValidated(
                draftId: $draft->id,
                status: $draft->status,
                unconfirmedAiInferredCount: $unconfirmed->count(),
            ),
        );

        return ['draft' => $draft, 'unconfirmedFields' => $unconfirmed->pluck('field_name')->values()->all()];
    }

    /**
     * @return Collection<int, FieldProvenance>
     */
    private function latestFieldProvenances(int $draftId): Collection
    {
        return FieldProvenance::query()
            ->where('entity_type', FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT)
            ->where('entity_id', $draftId)
            ->orderByDesc('id')
            ->get()
            ->unique('field_name');
    }
}
