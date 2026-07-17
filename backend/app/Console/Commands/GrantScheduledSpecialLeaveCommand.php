<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Commands\GrantScheduledSpecialLeave;
use Illuminate\Console\Command;

/**
 * 特別休暇種別ごとの自動付与ルールに基づき特別休暇を自動付与する。cronから毎日実行する想定。
 */
class GrantScheduledSpecialLeaveCommand extends Command
{
    protected $signature = 'special-leave:grant-scheduled';

    protected $description = '継続勤務期間・出勤率に基づき特別休暇を自動付与する';

    public function handle(CommandBus $commandBus): int
    {
        $grantedIds = $commandBus->dispatch(new GrantScheduledSpecialLeave);
        $this->info(count($grantedIds).' 件の特別休暇を自動付与しました。');

        return self::SUCCESS;
    }
}
