<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\AttendanceImport\Commands\ConfirmFieldProvenance;
use App\Domain\AttendanceImport\Events\FieldProvenanceConfirmed;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\FieldProvenance;

/**
 * ユーザーがAI推定値を確認したことを記録する(docs/26「AI生成値の出所管理」)。
 *
 * @implements CommandHandler<ConfirmFieldProvenance>
 */
class ConfirmFieldProvenanceHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): FieldProvenance
    {
        assert($command instanceof ConfirmFieldProvenance);

        $provenance = FieldProvenance::query()->findOrFail($command->fieldProvenanceId);
        $provenance->confirmed_at = now();
        $provenance->confirmed_by_user_id = $command->confirmedByUserId;
        $provenance->save();

        $this->eventStore->append(
            aggregateType: 'field_provenance',
            aggregateId: (string) $provenance->id,
            event: new FieldProvenanceConfirmed(
                fieldProvenanceId: $provenance->id,
                confirmedByUserId: $command->confirmedByUserId,
            ),
        );

        return $provenance;
    }
}
