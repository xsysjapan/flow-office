<?php

namespace Tests\Support;

use App\Domain\EventSourcing\CommandBus;

/**
 * 入口の1回の呼び出しで、入口が直接dispatchするCommandが1つだけであることを確かめる(TestCaseで使う)。
 */
trait AssertsSingleTopLevelCommand
{
    protected function recordCommands(): RecordingCommandBus
    {
        $bus = new RecordingCommandBus($this->app);
        $this->app->instance(CommandBus::class, $bus);

        return $bus;
    }

    protected function assertSingleTopLevelCommand(RecordingCommandBus $bus, string $expectedClass): void
    {
        $classes = array_map(fn ($command) => $command::class, $bus->topLevelCommands());

        $this->assertCount(1, $classes, '入口が直接dispatchしたCommand: '.implode(', ', $classes));
        $this->assertSame($expectedClass, $classes[0]);
    }
}
