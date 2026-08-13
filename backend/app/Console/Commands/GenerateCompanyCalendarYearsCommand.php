<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Commands\GenerateCompanyCalendarYears;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Console\Command;

/**
 * UC-C014: カレンダー年度を定期バッチで生成する。cronから毎日実行する想定。べき等。
 */
class GenerateCompanyCalendarYearsCommand extends Command
{
    protected $signature = 'calendar:generate-years';

    protected $description = '会社カレンダー本体ごとに、必要なカレンダー年度を下書き状態で生成する';

    public function handle(CommandBus $commandBus): int
    {
        $generatedIds = $commandBus->dispatch(new GenerateCompanyCalendarYears(isBatch: true));

        $this->info(count($generatedIds).' 件のカレンダー年度を生成しました。');

        return self::SUCCESS;
    }
}
