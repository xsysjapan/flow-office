<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarAggregate;
use App\Domain\Attendance\Commands\SetDefaultCompanyCalendar;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use Illuminate\Validation\ValidationException;

/**
 * docs/16-database-schema.md: 会社カレンダー本体で有効なデフォルトは常に高々1件。
 * 新しい本体をデフォルトに設定した場合は既存のデフォルトを解除する
 * (`SetDefaultWorkStyleHandler`と同じ考え方)。`ProvisionalScheduleCalculator`が
 * フォールバック判定に使うため、is_defaultの整合性がここで担保される。
 *
 * @implements CommandHandler<SetDefaultCompanyCalendar>
 */
class SetDefaultCompanyCalendarHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendar
    {
        assert($command instanceof SetDefaultCompanyCalendar);

        $calendar = CompanyCalendar::query()->find($command->companyCalendarId);

        if ($calendar === null) {
            throw ValidationException::withMessages(['company_calendar_id' => '指定された会社カレンダーが見つかりません。']);
        }

        if ($calendar->is_default) {
            return $calendar;
        }

        $previousDefault = CompanyCalendar::query()->where('is_default', true)->first();

        CompanyCalendarAggregate::retrieve($calendar->id)
            ->changeDefault($previousDefault?->id, $command->changedByUserId)
            ->persist();

        return CompanyCalendar::query()->findOrFail($calendar->id);
    }
}
