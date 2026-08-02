<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\BackfillUserRoles;
use Illuminate\Console\Command;

/**
 * Seeder等が直接作成した既存のrole_userを、移行時点の合成イベントとして補完する。
 */
class BackfillUserRolesCommand extends Command
{
    protected $signature = 'users:backfill-roles';

    protected $description = '既存ユーザーのロール割り当てをEventStoreへ補完する';

    public function handle(CommandBus $commandBus): int
    {
        $count = $commandBus->dispatch(new BackfillUserRoles);
        $this->info("{$count} 件のユーザーのロール割り当てを補完しました。");

        return self::SUCCESS;
    }
}
