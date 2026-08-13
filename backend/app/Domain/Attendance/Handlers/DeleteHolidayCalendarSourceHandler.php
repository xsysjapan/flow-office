<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\HolidayCalendarSourceAggregate;
use App\Domain\Attendance\Commands\DeleteHolidayCalendarSource;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use Illuminate\Validation\ValidationException;

/**
 * UC-C012: 祝日iCalendarソースを削除する。無効化(disable)したソースを再度有効化する
 * 手段が無く、登録し直すしかなかったため、不要になったソースを削除できるようにする。
 * いずれかの会社カレンダーが現在このソースを使用している場合は削除できない
 * (DeleteCompanyCalendarHandlerの参照ガードと同じ考え方)。
 *
 * @implements CommandHandler<DeleteHolidayCalendarSource>
 */
class DeleteHolidayCalendarSourceHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteHolidayCalendarSource);

        if (CompanyCalendar::query()->where('holiday_calendar_source_id', $command->holidayCalendarSourceId)->exists()) {
            throw ValidationException::withMessages([
                'holiday_calendar_source' => ['このソースを使用している会社カレンダーがあるため削除できません。カレンダー側で割当を解除してから削除してください。'],
            ]);
        }

        HolidayCalendarSourceAggregate::retrieve($command->holidayCalendarSourceId)
            ->delete(deletedByUserId: $command->deletedByUserId)
            ->persist();

        return null;
    }
}
