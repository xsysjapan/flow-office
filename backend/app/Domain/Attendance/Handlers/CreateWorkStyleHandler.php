<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\Attendance\Events\WorkStyleCreated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\WorkStyle;

/**
 * UC-C002: 勤務形態を作成する。
 *
 * @implements CommandHandler<CreateWorkStyle>
 */
class CreateWorkStyleHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): WorkStyle
    {
        assert($command instanceof CreateWorkStyle);

        $workStyle = WorkStyle::query()->create($command->attributes);

        $this->eventStore->append(
            aggregateType: 'work_style',
            aggregateId: (string) $workStyle->id,
            event: new WorkStyleCreated(
                workStyleId: $workStyle->id,
                attributes: $command->attributes,
                createdByUserId: $command->createdByUserId,
            ),
        );

        return $workStyle;
    }
}
