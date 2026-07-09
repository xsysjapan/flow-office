<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\SyncUsersFromMs365;
use Illuminate\Console\Command;

/**
 * UC-002: MS365ユーザーを同期する。cronから定期実行する想定。
 */
class SyncUsersFromMs365Command extends Command
{
    protected $signature = 'users:sync-ms365';

    protected $description = 'Microsoft GraphからユーザーをアプリDBへ同期する';

    public function handle(CommandBus $commandBus): int
    {
        $count = $commandBus->dispatch(new SyncUsersFromMs365);
        $this->info("{$count} 件のユーザーを同期しました。");

        return self::SUCCESS;
    }
}
