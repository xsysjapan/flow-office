<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\RegenerateCompanyCalendarYear;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\Attendance\Services\CalendarWeekdayPatternDayGenerator;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 下書きのカレンダー年度の日別設定を、本体の現在の曜日休日パターンから丸ごと再生成する
 * (以前の手動編集は破棄)。公開済み年度は対象外。本体に祝日iCalendarソースがあれば同期も記録する
 * (取得・解析の失敗は拒否にせず、日の更新は残して同期の失敗を記録する)。
 *
 * @implements CommandHandler<RegenerateCompanyCalendarYear>
 */
class RegenerateCompanyCalendarYearHandler implements CommandHandler
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly CalendarWeekdayPatternDayGenerator $dayGenerator,
    ) {}

    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof RegenerateCompanyCalendarYear);

        $year = CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        if ($year->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['公開済みのカレンダー年度は再作成できません。'],
            ]);
        }

        $companyCalendar = $year->companyCalendar;

        $days = $this->dayGenerator->generate(
            Carbon::parse($year->starts_on),
            Carbon::parse($year->ends_on),
            $companyCalendar->effectiveWeekdayHolidayPattern(),
        );

        $this->commandBus->dispatch(new UpdateCompanyCalendarDays(
            companyCalendarYearId: $year->id,
            days: $days,
            updatedByUserId: $command->updatedByUserId,
        ));

        if ($companyCalendar->holiday_calendar_source_id !== null) {
            $this->commandBus->dispatch(new SyncHolidayCalendarSource(
                holidayCalendarSourceId: $companyCalendar->holiday_calendar_source_id,
                syncedByUserId: $command->updatedByUserId,
                companyCalendarYearId: $year->id,
            ));
        }

        return $year->refresh();
    }
}
