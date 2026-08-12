<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\HolidayCalendarSourceDisabled;
use App\Domain\Attendance\Events\HolidayCalendarSourceRegistered;
use App\Domain\Attendance\Events\HolidayCalendarSourceSynced;
use App\Domain\Attendance\Events\HolidayCalendarSourceSyncFailed;
use App\Domain\Attendance\Events\HolidayCalendarSourceSyncReverted;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * holiday_calendar_source集約 (UC-C012)。
 */
class HolidayCalendarSourceAggregate extends AggregateRoot
{
    public function register(string $name, string $icsUrl, string $registeredByUserId): self
    {
        $this->recordThat(new HolidayCalendarSourceRegistered(
            name: $name,
            icsUrl: $icsUrl,
            registeredByUserId: $registeredByUserId,
        ));

        return $this;
    }

    /**
     * @param  list<array{ics_uid: string, date: string, name: string, action: string}>  $eventChanges
     * @param  list<array{company_calendar_day_id: int, date: string, is_public_holiday: bool, public_holiday_name: ?string}>  $dayChanges
     * @param  list<array{company_calendar_day_id: int, date: string}>  $protectedConflicts
     */
    public function recordSynced(array $eventChanges, array $dayChanges, array $protectedConflicts, ?string $syncedByUserId): self
    {
        $this->recordThat(new HolidayCalendarSourceSynced(
            eventChanges: $eventChanges,
            dayChanges: $dayChanges,
            protectedConflicts: $protectedConflicts,
            syncedByUserId: $syncedByUserId,
        ));

        return $this;
    }

    public function recordSyncFailed(string $error, ?string $syncedByUserId): self
    {
        $this->recordThat(new HolidayCalendarSourceSyncFailed(
            error: $error,
            syncedByUserId: $syncedByUserId,
        ));

        return $this;
    }

    public function disable(string $disabledByUserId): self
    {
        $this->recordThat(new HolidayCalendarSourceDisabled(
            disabledByUserId: $disabledByUserId,
        ));

        return $this;
    }

    /**
     * @param  list<array{company_calendar_day_id: int, is_public_holiday: bool, public_holiday_name: ?string}>  $dayReverts
     */
    public function revertLastSync(array $dayReverts, int $revertedStoredEventId, string $revertedByUserId): self
    {
        $this->recordThat(new HolidayCalendarSourceSyncReverted(
            dayReverts: $dayReverts,
            revertedStoredEventId: $revertedStoredEventId,
            revertedByUserId: $revertedByUserId,
        ));

        return $this;
    }
}
