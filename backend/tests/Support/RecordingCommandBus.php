<?php

namespace Tests\Support;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;

/**
 * dispatchの入れ子の深さを数えるテスト用CommandBus。
 * 深さ0(入口から直接)のCommandと、Reactor等からの入れ子を含む全部のCommandを記録する。
 */
class RecordingCommandBus extends CommandBus
{
    private int $depth = 0;

    /** @var list<Command> */
    private array $topLevel = [];

    /** @var list<Command> */
    private array $all = [];

    public function dispatch(Command $command): mixed
    {
        if ($this->depth === 0) {
            $this->topLevel[] = $command;
        }
        $this->all[] = $command;

        $this->depth++;
        try {
            return parent::dispatch($command);
        } finally {
            $this->depth--;
        }
    }

    /** @return list<Command> */
    public function topLevelCommands(): array
    {
        return $this->topLevel;
    }

    /** @return list<Command> */
    public function allCommands(): array
    {
        return $this->all;
    }
}
