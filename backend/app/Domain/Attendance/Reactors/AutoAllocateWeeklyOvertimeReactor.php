<?php

namespace App\Domain\Attendance\Reactors;

use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Services\WeeklyOvertimeAutoAllocator;
use App\Models\AttendanceDay;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

final class AutoAllocateWeeklyOvertimeReactor extends Reactor
{
    public function __construct(private readonly WeeklyOvertimeAutoAllocator $allocator) {}

    public function onAttendanceDayCalculated(AttendanceDayCalculated $event): void
    {
        $day = AttendanceDay::query()->find($event->aggregateRootUuid());
        if ($day !== null) {
            $this->allocator->allocateNonPrescribed($day->user_id, $day->work_date);
        }
    }
}
