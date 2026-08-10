<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\CompanyCalendarCreated;
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
}
