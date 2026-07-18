<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\AttendanceImport\Commands\CreateMonthlyAttendanceDraft;
use App\Domain\AttendanceImport\Events\MonthlyAttendanceDraftCreated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;

/**
 * @implements CommandHandler<CreateMonthlyAttendanceDraft>
 */
class CreateMonthlyAttendanceDraftHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): MonthlyAttendanceDraft
    {
        assert($command instanceof CreateMonthlyAttendanceDraft);

        $draft = MonthlyAttendanceDraft::query()->create([
            'user_id' => $command->userId,
            'target_month' => $command->targetMonth,
            'status' => MonthlyDraftStatus::DRAFT,
            'version' => 1,
            'source_type' => $command->sourceType,
            'source_reference' => $command->sourceReference,
            'created_by_user_id' => $command->createdByUserId,
        ]);

        $this->eventStore->append(
            aggregateType: 'monthly_attendance_draft',
            aggregateId: (string) $draft->id,
            event: new MonthlyAttendanceDraftCreated(
                draftId: $draft->id,
                userId: $command->userId,
                targetMonth: $command->targetMonth,
                createdByUserId: $command->createdByUserId,
            ),
        );

        return $draft;
    }
}
