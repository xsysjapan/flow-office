<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarAggregate;
use App\Domain\Attendance\Commands\DeleteCompanyCalendar;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use App\Models\WorkStyle;
use Illuminate\Validation\ValidationException;

/**
 * 会社カレンダー本体を削除する。デフォルトカレンダー(is_default=true)や、
 * いずれかの勤務形態(work_styles.company_calendar_id)から参照されているカレンダーは
 * 削除できない(docs/16-database-schema.md)。
 *
 * @implements CommandHandler<DeleteCompanyCalendar>
 */
class DeleteCompanyCalendarHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteCompanyCalendar);

        $companyCalendar = CompanyCalendar::query()->find($command->companyCalendarId);

        if ($companyCalendar === null) {
            throw ValidationException::withMessages(['company_calendar_id' => '指定された会社カレンダーが見つかりません。']);
        }

        if ($companyCalendar->is_default) {
            throw ValidationException::withMessages(['company_calendar_id' => 'デフォルトカレンダーは削除できません。']);
        }

        if (WorkStyle::query()->where('company_calendar_id', $companyCalendar->id)->exists()) {
            throw ValidationException::withMessages(['company_calendar_id' => 'このカレンダーを使用している勤務形態があるため削除できません。']);
        }

        CompanyCalendarAggregate::retrieve($companyCalendar->id)
            ->delete($command->deletedByUserId)
            ->persist();

        return null;
    }
}
