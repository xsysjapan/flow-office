<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\GrantScheduledPaidLeave;
use Illuminate\Console\Command;

/**
 * UC-P002: 有給を自動付与する。cronから毎日実行する想定。
 */
class GrantScheduledPaidLeaveCommand extends Command
{
    protected $signature = 'paid-leave:grant-scheduled';

    protected $description = '継続勤務期間・出勤率に基づき有給を自動付与する';

    public function handle(CommandBus $commandBus): int
    {
        $grantedIds = $commandBus->dispatch(new GrantScheduledPaidLeave);
        $this->info(count($grantedIds).' 件の有給を自動付与しました。');

        return self::SUCCESS;
    }
}
