<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\WorkCalendarCreated;
use App\Domain\Attendance\Events\WorkCalendarDaysUpdated;
use App\Domain\Attendance\Events\WorkCalendarPublished;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * work_calendar集約。主キー(work_calendars.id)はコマンド側が決めたUUIDで、行の新規作成自体は
 * WorkCalendarProjectorに委ねられる。`work_calendar_days`はこの集約の子データとして扱い、
 * 独立した集約を持たない(WorkCalendarDaysUpdated参照)。
 */
class WorkCalendarAggregate extends AggregateRoot
{
    public function create(
        string $name,
        int $fiscalYear,
        string $startsOn,
        string $endsOn,
        int $weekStartsOn,
        string $createdByUserId,
    ): self {
        $this->recordThat(new WorkCalendarCreated(
            name: $name,
            fiscalYear: $fiscalYear,
            startsOn: $startsOn,
            endsOn: $endsOn,
            weekStartsOn: $weekStartsOn,
            createdByUserId: $createdByUserId,
        ));

        return $this;
    }

    /**
     * @param  list<array{date: string, day_type: string, is_working_day: bool, is_legal_holiday: bool, is_company_holiday: bool, note: ?string}>  $days
     */
    public function updateDays(array $days, string $updatedByUserId): self
    {
        $this->recordThat(new WorkCalendarDaysUpdated(
            days: $days,
            updatedByUserId: $updatedByUserId,
        ));

        return $this;
    }

    public function publish(string $publishedByUserId): self
    {
        $this->recordThat(new WorkCalendarPublished(
            publishedByUserId: $publishedByUserId,
        ));

        return $this;
    }
}
