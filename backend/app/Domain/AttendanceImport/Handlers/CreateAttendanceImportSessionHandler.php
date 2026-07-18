<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\AttendanceImport\Commands\CreateAttendanceImportSession;
use App\Domain\AttendanceImport\Events\AttendanceImportSessionCreated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\AttendanceImportSession;
use App\Models\ImportSessionStatus;

/**
 * @implements CommandHandler<CreateAttendanceImportSession>
 */
class CreateAttendanceImportSessionHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): AttendanceImportSession
    {
        assert($command instanceof CreateAttendanceImportSession);

        $session = AttendanceImportSession::query()->create([
            'user_id' => $command->userId,
            'target_month' => $command->targetMonth,
            'status' => ImportSessionStatus::CREATED,
            'source_type' => $command->sourceType,
            'source_file_name' => $command->sourceFileName,
            'source_file_hash' => $command->sourceFileHash,
            'client_type' => $command->clientType,
            'integration_id' => $command->integrationId,
        ]);

        $this->eventStore->append(
            aggregateType: 'attendance_import_session',
            aggregateId: (string) $session->id,
            event: new AttendanceImportSessionCreated(
                sessionId: $session->id,
                userId: $command->userId,
                targetMonth: $command->targetMonth,
                sourceFileHash: $command->sourceFileHash,
            ),
        );

        return $session;
    }
}
