<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance.legal_holiday_designated
 *
 * 法定休日「決めない方式」における、特定の週の法定休日の指定・再指定。
 * 集約ID(legal_holiday_designations.id)は`aggregateRootUuid()`から取得する。
 */
class LegalHolidayDesignated extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $weekStartDate,
        public readonly ?string $previousDesignatedDate,
        public readonly string $designatedDate,
        public readonly string $reason,
        public readonly string $designatedByUserId,
    ) {}
}
