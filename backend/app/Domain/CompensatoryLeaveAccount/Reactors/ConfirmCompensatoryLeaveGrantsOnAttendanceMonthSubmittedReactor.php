<?php

namespace App\Domain\CompensatoryLeaveAccount\Reactors;

use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Domain\CompensatoryLeaveAccount\Commands\ConfirmCompensatoryLeaveGrantsForPeriod;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 月次勤怠の提出を受けて、対象月の休日出勤の下書き付与を確定する(代休口座の文脈のReactor。原則15)。
 * 対象期間は月の初日・末日(日付範囲で判定する。文字列の前方一致はしない)。
 */
class ConfirmCompensatoryLeaveGrantsOnAttendanceMonthSubmittedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onAttendanceMonthSubmitted(AttendanceMonthSubmitted $event): void
    {
        $month = Carbon::createFromFormat('Y-m', $event->yearMonth);

        $this->commandBus->dispatch(new ConfirmCompensatoryLeaveGrantsForPeriod(
            userId: $event->userId,
            periodStart: $month->copy()->startOfMonth()->toDateString(),
            periodEnd: $month->copy()->endOfMonth()->toDateString(),
            submittedAt: $event->createdAt()->toDateTimeString(),
        ));
    }
}
