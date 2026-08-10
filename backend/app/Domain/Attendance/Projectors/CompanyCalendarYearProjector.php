<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\CompanyCalendarDaysUpdated;
use App\Domain\Attendance\Events\CompanyCalendarYearArchived;
use App\Domain\Attendance\Events\CompanyCalendarYearCreated;
use App\Domain\Attendance\Events\CompanyCalendarYearPublished;
use App\Domain\Attendance\Events\CompanyCalendarYearUnpublished;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * company_calendar_year.*イベントからcompany_calendar_years / company_calendar_daysを
 * 作成・更新する(.claude/skills/add-projection参照)。company_calendar_daysは
 * company_calendar_yearの子データとして扱い、CompanyCalendarDaysUpdatedイベントに含まれる
 * 日別設定をそのまま反映する。
 *
 * schedule_state/is_public_holiday(新)とday_type/is_working_day/is_company_holiday(旧)は
 * 相互に導出可能なため、どちらの形式で渡されても両方を整合させて書き込む
 * (docs/16-database-schema.md UC-C010、2段階廃止のため旧カラムも書き込み続ける)。
 */
class CompanyCalendarYearProjector extends Projector
{
    public function onCompanyCalendarYearCreated(CompanyCalendarYearCreated $event): void
    {
        CompanyCalendarYear::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'company_calendar_id' => $event->companyCalendarId,
                'fiscal_year' => $event->fiscalYear,
                'starts_on' => $event->startsOn,
                'ends_on' => $event->endsOn,
                'status' => 'draft',
                'generated_from' => $event->generatedFrom,
            ],
        );
    }

    public function onCompanyCalendarDaysUpdated(CompanyCalendarDaysUpdated $event): void
    {
        $companyCalendarYear = CompanyCalendarYear::query()->findOrFail($event->aggregateRootUuid());

        foreach ($event->days as $day) {
            // 'date' はdateキャストのためDB上はdatetime文字列で保存される。
            // updateOrCreateの厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
            $calendarDay = $companyCalendarYear->days()->whereDate('date', $day['date'])->first()
                ?? $companyCalendarYear->days()->make(['date' => $day['date']]);

            $scheduleStateGiven = $day['schedule_state'] ?? null;
            $isWorkingDayGiven = $day['is_working_day'] ?? null;

            if ($scheduleStateGiven !== null) {
                $scheduleState = $scheduleStateGiven;
                $isWorkingDay = $scheduleState === CompanyCalendarDay::SCHEDULE_WORK;
            } else {
                $isWorkingDay = $isWorkingDayGiven ?? true;
                $scheduleState = $isWorkingDay ? CompanyCalendarDay::SCHEDULE_WORK : CompanyCalendarDay::SCHEDULE_OFF;
            }

            $calendarDay->fill([
                'day_type' => $day['day_type'],
                'is_working_day' => $isWorkingDay,
                'is_legal_holiday' => $day['is_legal_holiday'] ?? false,
                'is_company_holiday' => $day['is_company_holiday'] ?? false,
                'is_public_holiday' => $day['is_public_holiday'] ?? false,
                'public_holiday_name' => $day['public_holiday_name'] ?? null,
                'schedule_state' => $scheduleState,
                'note' => $day['note'] ?? null,
            ])->save();
        }
    }

    public function onCompanyCalendarYearPublished(CompanyCalendarYearPublished $event): void
    {
        CompanyCalendarYear::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => 'published',
            'published_at' => Carbon::now(),
            'published_by_user_id' => $event->publishedByUserId,
        ]);
    }

    public function onCompanyCalendarYearUnpublished(CompanyCalendarYearUnpublished $event): void
    {
        CompanyCalendarYear::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => 'draft',
        ]);
    }

    public function onCompanyCalendarYearArchived(CompanyCalendarYearArchived $event): void
    {
        CompanyCalendarYear::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => 'archived',
        ]);
    }
}
