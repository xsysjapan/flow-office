<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CreateRotationPattern;
use App\Domain\Attendance\Events\RotationPatternCreated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\RotationPattern;

/**
 * 指示書 8.4節: 交代制勤務のローテーションパターン(A勤・B勤・C勤・休の繰り返し周期)を
 * 1つの働き方の中にまとめて登録する。
 *
 * @implements CommandHandler<CreateRotationPattern>
 */
class CreateRotationPatternHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): RotationPattern
    {
        assert($command instanceof CreateRotationPattern);

        $pattern = RotationPattern::query()->create([
            'work_style_id' => $command->workStyleId,
            'name' => $command->name,
            'cycle_length' => count($command->items),
        ]);

        foreach ($command->items as $item) {
            $pattern->items()->create([
                'sequence' => $item['sequence'],
                'shift_pattern_id' => $item['shift_pattern_id'],
            ]);
        }

        $this->eventStore->append(
            aggregateType: 'rotation_pattern',
            aggregateId: (string) $pattern->id,
            event: new RotationPatternCreated(
                rotationPatternId: $pattern->id,
                workStyleId: $command->workStyleId,
                name: $command->name,
                cycleLength: $pattern->cycle_length,
                items: $command->items,
                createdByUserId: $command->createdByUserId,
            ),
        );

        return $pattern->load('items.shiftPattern');
    }
}
