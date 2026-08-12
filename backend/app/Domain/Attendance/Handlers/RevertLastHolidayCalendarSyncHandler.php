<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\HolidayCalendarSourceAggregate;
use App\Domain\Attendance\Commands\RevertLastHolidayCalendarSync;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\HolidayCalendarSource;
use Illuminate\Validation\ValidationException;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * UC-C012 手順4後半: 祝日同期の直近1回分を取消す。取消対象の同期が変更した
 * `company_calendar_days`の`is_public_holiday`/`public_holiday_name`を、その同期実行の
 * `holiday_calendar_source.synced`イベントに保存された同期直前の値
 * (`previous_is_public_holiday`/`previous_public_holiday_name`)に戻す。手動変更(保護)された
 * 日は元の同期でも変更されていない(`protected_conflicts`側)ため、この取消対象にもならない。
 *
 * @implements CommandHandler<RevertLastHolidayCalendarSync>
 */
class RevertLastHolidayCalendarSyncHandler implements CommandHandler
{
    public function handle(Command $command): HolidayCalendarSource
    {
        assert($command instanceof RevertLastHolidayCalendarSync);

        $source = HolidayCalendarSource::query()->find($command->holidayCalendarSourceId);

        if ($source === null) {
            throw ValidationException::withMessages(['holiday_calendar_source_id' => '指定された祝日iCalendarソースが見つかりません。']);
        }

        $lastSyncedEvent = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $source->id)
            ->where('event_class', 'holiday_calendar_source.synced')
            ->orderByDesc('id')
            ->first();

        if ($lastSyncedEvent === null) {
            throw new DomainRuleException('取消可能な同期実行履歴がありません。');
        }

        $dayChanges = $lastSyncedEvent->event_properties['dayChanges'] ?? [];

        $dayReverts = array_map(fn (array $dayChange) => [
            'company_calendar_day_id' => $dayChange['company_calendar_day_id'],
            'is_public_holiday' => (bool) ($dayChange['previous_is_public_holiday'] ?? false),
            'public_holiday_name' => $dayChange['previous_public_holiday_name'] ?? null,
        ], $dayChanges);

        HolidayCalendarSourceAggregate::retrieve($source->id)
            ->revertLastSync(
                dayReverts: $dayReverts,
                revertedStoredEventId: $lastSyncedEvent->id,
                revertedByUserId: $command->revertedByUserId,
            )
            ->persist();

        return HolidayCalendarSource::query()->findOrFail($source->id);
    }
}
