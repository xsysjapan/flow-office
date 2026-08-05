<?php

namespace App\Domain\CompensatoryLeave\Reactors;

use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\CompensatoryLeave\Commands\SyncCompensatoryLeaveGrant;
use App\Domain\EventSourcing\CommandBus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 勤怠実績(日次計算)の反映・削除を受けて、対象日の代休Grant(draft)を同期する
 * (SyncCompensatoryLeaveGrantHandler参照)。いずれのイベントもattendance_day集約が記録する
 * ため、aggregateRootUuid()がattendanceDayIdになる。
 */
class SyncCompensatoryLeaveGrantOnAttendanceDayCalculatedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onAttendanceDayCalculated(AttendanceDayCalculated $event): void
    {
        $this->commandBus->dispatch(new SyncCompensatoryLeaveGrant(attendanceDayId: $event->aggregateRootUuid()));
    }

    public function onAttendanceDailyCalculationAdjusted(AttendanceDailyCalculationAdjusted $event): void
    {
        $this->commandBus->dispatch(new SyncCompensatoryLeaveGrant(attendanceDayId: $event->aggregateRootUuid()));
    }

    public function onAttendanceDayDeleted(AttendanceDayDeleted $event): void
    {
        $this->commandBus->dispatch(new SyncCompensatoryLeaveGrant(attendanceDayId: $event->aggregateRootUuid()));
    }
}
