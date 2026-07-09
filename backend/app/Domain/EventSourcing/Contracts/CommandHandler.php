<?php

namespace App\Domain\EventSourcing\Contracts;

/**
 * @template TCommand of Command
 */
interface CommandHandler
{
    /**
     * 業務ルールを検証し、EventStoreへイベントを追記し、正データを更新する。
     * DBトランザクションはCommandBus側で開始される。
     *
     * @param  TCommand  $command
     */
    public function handle(Command $command): mixed;
}
