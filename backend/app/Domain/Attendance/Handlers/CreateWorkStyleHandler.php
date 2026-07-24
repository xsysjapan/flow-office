<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\WorkStyleAggregate;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\WorkStyle;
use Illuminate\Support\Str;

/**
 * UC-C002: 勤務形態を作成する。
 *
 * @implements CommandHandler<CreateWorkStyle>
 */
class CreateWorkStyleHandler implements CommandHandler
{
    public function handle(Command $command): WorkStyle
    {
        assert($command instanceof CreateWorkStyle);

        $id = (string) Str::uuid();

        WorkStyleAggregate::retrieve($id)
            ->create($command->attributes, $command->createdByUserId)
            ->persist();

        return WorkStyle::query()->findOrFail($id);
    }
}
