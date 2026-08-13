<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\EventSourcing\CommandBus;
use App\Models\HolidayCalendarSource;
use Illuminate\Console\Command;

/**
 * UC-C012 手順2: 祝日iCalendarソースを同期する。cronから毎日実行する想定。
 * 無効化済み(disabled_at設定済み)のソースは対象外にする(UC-C012手順5)。
 */
class SyncHolidayCalendarSourcesCommand extends Command
{
    protected $signature = 'holiday-calendar:sync {sourceId?}';

    protected $description = '祝日iCalendarソースを同期する(引数省略時は有効な全ソースが対象)';

    public function handle(CommandBus $commandBus): int
    {
        $query = HolidayCalendarSource::query()->whereNull('disabled_at');

        if ($sourceId = $this->argument('sourceId')) {
            $query->whereKey($sourceId);
        }

        $sources = $query->get();

        foreach ($sources as $source) {
            $synced = $commandBus->dispatch(new SyncHolidayCalendarSource(
                holidayCalendarSourceId: $source->id,
                syncedByUserId: null,
            ));

            $this->info("{$synced->name}: {$synced->sync_status}");
        }

        $this->info(count($sources).' 件の祝日iCalendarソースを同期しました。');

        return self::SUCCESS;
    }
}
