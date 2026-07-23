<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\RotationPatternAggregate;
use App\Domain\Attendance\Commands\CreateRotationPattern;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\RotationPattern;
use Illuminate\Support\Str;

/**
 * 指示書 8.4節: 交代制勤務のローテーションパターン(A勤・B勤・C勤・休の繰り返し周期)を
 * 1つの働き方の中にまとめて登録する。
 *
 * @implements CommandHandler<CreateRotationPattern>
 */
class CreateRotationPatternHandler implements CommandHandler
{
    public function handle(Command $command): RotationPattern
    {
        assert($command instanceof CreateRotationPattern);

        $id = (string) Str::uuid();

        RotationPatternAggregate::retrieve($id)
            ->create(
                workStyleId: $command->workStyleId,
                name: $command->name,
                items: $command->items,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        return RotationPattern::query()->findOrFail($id)->load('items.shiftPattern');
    }
}
