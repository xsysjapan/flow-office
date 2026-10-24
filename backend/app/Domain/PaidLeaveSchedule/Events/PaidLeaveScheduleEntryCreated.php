<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveScheduleEntryCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $scheduleEntryId,
        public readonly string $scheduledOn,
        public readonly string $category,
        public readonly float $candidateGrantDays,
        public readonly bool $isDeterminate = true,
        /** 名目の継続勤務月数。古いイベントには無い(null)。 */
        public readonly ?int $nominalServiceMonths = null,
    ) {}
}
