<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\CompanyCalendarDaysUpdated;
use App\Domain\Attendance\Events\CompanyCalendarYearArchived;
use App\Domain\Attendance\Events\CompanyCalendarYearCreated;
use App\Domain\Attendance\Events\CompanyCalendarYearPublished;
use App\Domain\Attendance\Events\CompanyCalendarYearUnpublished;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * company_calendar_year集約(年度)。`company_calendar_days`はこの集約の子データとして扱い、
 * 独立した集約を持たない(CompanyCalendarDaysUpdated参照。UC-C009・UC-C010)。
 */
class CompanyCalendarYearAggregate extends AggregateRoot
{
    public function create(
        string $companyCalendarId,
        int $fiscalYear,
        string $startsOn,
        string $endsOn,
        string $generatedFrom,
        string $createdByUserId,
    ): self {
        $this->recordThat(new CompanyCalendarYearCreated(
            companyCalendarId: $companyCalendarId,
            fiscalYear: $fiscalYear,
            startsOn: $startsOn,
            endsOn: $endsOn,
            generatedFrom: $generatedFrom,
            createdByUserId: $createdByUserId,
        ));

        return $this;
    }

    /**
     * @param  list<array{date: string, day_type: string, is_working_day: bool, is_legal_holiday: bool, is_company_holiday: bool, is_public_holiday?: bool, public_holiday_name?: ?string, schedule_state?: string, note: ?string}>  $days
     */
    public function updateDays(array $days, string $updatedByUserId): self
    {
        $this->recordThat(new CompanyCalendarDaysUpdated(
            days: $days,
            updatedByUserId: $updatedByUserId,
        ));

        return $this;
    }

    public function publish(string $publishedByUserId): self
    {
        $this->recordThat(new CompanyCalendarYearPublished(
            publishedByUserId: $publishedByUserId,
        ));

        return $this;
    }

    public function unpublish(string $unpublishedByUserId): self
    {
        $this->recordThat(new CompanyCalendarYearUnpublished(
            unpublishedByUserId: $unpublishedByUserId,
        ));

        return $this;
    }

    public function archive(string $archivedByUserId): self
    {
        $this->recordThat(new CompanyCalendarYearArchived(
            archivedByUserId: $archivedByUserId,
        ));

        return $this;
    }
}
