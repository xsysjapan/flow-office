<?php

namespace App\Domain\CompensatoryLeave\Reactors;

use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Domain\CompensatoryLeave\Commands\ConfirmCompensatoryLeaveGrantsForMonth;
use App\Domain\EventSourcing\CommandBus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 月次勤怠の提出を受けて、対象月に属するdraft状態の代休Grantを全て確定する
 * (ConfirmCompensatoryLeaveGrantsForMonthHandler参照)。AttendanceMonthSubmittedイベントは
 * userId/yearMonthを直接持つため(AttendanceMonthProjector::onAttendanceMonthSubmitted参照)、
 * 別途AttendanceMonthモデルを読み直す必要はない。
 */
class ConfirmCompensatoryLeaveGrantsOnAttendanceMonthSubmittedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onAttendanceMonthSubmitted(AttendanceMonthSubmitted $event): void
    {
        $this->commandBus->dispatch(new ConfirmCompensatoryLeaveGrantsForMonth(
            userId: $event->userId,
            yearMonth: $event->yearMonth,
            submittedAt: $event->createdAt(),
        ));
    }
}
