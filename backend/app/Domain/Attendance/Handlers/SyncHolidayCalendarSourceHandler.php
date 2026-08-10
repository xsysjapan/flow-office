<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\HolidayCalendarSourceAggregate;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\Attendance\Services\HolidayCalendarSynchronizer;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\HolidayCalendarSource;
use RuntimeException;

/**
 * UC-C012 手順2〜4: 祝日iCalendarソースを同期する。
 *
 * 取得・パース失敗時は`sync_status=failed`とし、`company_calendar_days`は一切変更しない
 * (HolidayCalendarSourceSyncFailedイベントのみ記録する)。
 *
 * @implements CommandHandler<SyncHolidayCalendarSource>
 */
class SyncHolidayCalendarSourceHandler implements CommandHandler
{
    public function __construct(
        private readonly HolidayCalendarSynchronizer $synchronizer,
    ) {}

    public function handle(Command $command): HolidayCalendarSource
    {
        assert($command instanceof SyncHolidayCalendarSource);

        $source = HolidayCalendarSource::query()->findOrFail($command->holidayCalendarSourceId);

        try {
            $result = $this->synchronizer->synchronize($source);
        } catch (RuntimeException $e) {
            HolidayCalendarSourceAggregate::retrieve($source->id)
                ->recordSyncFailed(error: $e->getMessage(), syncedByUserId: $command->syncedByUserId)
                ->persist();

            return HolidayCalendarSource::query()->findOrFail($source->id);
        }

        HolidayCalendarSourceAggregate::retrieve($source->id)
            ->recordSynced(
                eventChanges: $result['event_changes'],
                dayChanges: $result['day_changes'],
                protectedConflicts: $result['protected_conflicts'],
                syncedByUserId: $command->syncedByUserId,
            )
            ->persist();

        return HolidayCalendarSource::query()->findOrFail($source->id);
    }
}
