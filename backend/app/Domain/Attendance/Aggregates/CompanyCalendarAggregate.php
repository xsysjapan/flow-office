<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\CompanyCalendarCreated;
use App\Domain\Attendance\Events\CompanyCalendarDefaultChanged;
use App\Domain\Attendance\Events\CompanyCalendarDeleted;
use App\Domain\Attendance\Events\CompanyCalendarUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * company_calendar集約(本体)。年度依存の状態(fiscal_year/starts_on/ends_on/status等)は
 * `CompanyCalendarYearAggregate`が持つ(UC-C009: 本体とカレンダー年度を分離して管理する)。
 * 主キー(company_calendars.id)はコマンド側が決めたUUIDで、行の新規作成自体は
 * CompanyCalendarProjectorに委ねられる。
 */
class CompanyCalendarAggregate extends AggregateRoot
{
    public function create(
        string $name,
        int $weekStartsOn,
        int $fiscalYearStartMonth,
        int $fiscalYearStartDay,
        string $createdByUserId,
    ): self {
        $this->recordThat(new CompanyCalendarCreated(
            name: $name,
            weekStartsOn: $weekStartsOn,
            fiscalYearStartMonth: $fiscalYearStartMonth,
            fiscalYearStartDay: $fiscalYearStartDay,
            createdByUserId: $createdByUserId,
        ));

        return $this;
    }

    public function update(
        string $name,
        int $weekStartsOn,
        int $fiscalYearStartMonth,
        int $fiscalYearStartDay,
        ?string $holidayCalendarSourceId,
        string $updatedByUserId,
    ): self {
        $this->recordThat(new CompanyCalendarUpdated(
            name: $name,
            weekStartsOn: $weekStartsOn,
            fiscalYearStartMonth: $fiscalYearStartMonth,
            fiscalYearStartDay: $fiscalYearStartDay,
            holidayCalendarSourceId: $holidayCalendarSourceId,
            updatedByUserId: $updatedByUserId,
        ));

        return $this;
    }

    public function changeDefault(?string $previousDefaultCompanyCalendarId, string $changedByUserId): self
    {
        $this->recordThat(new CompanyCalendarDefaultChanged(
            previousDefaultCompanyCalendarId: $previousDefaultCompanyCalendarId,
            changedByUserId: $changedByUserId,
        ));

        return $this;
    }

    public function delete(string $deletedByUserId): self
    {
        $this->recordThat(new CompanyCalendarDeleted(
            deletedByUserId: $deletedByUserId,
        ));

        return $this;
    }
}
