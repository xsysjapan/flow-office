<?php

namespace App\Domain\Attendance\Reactors;

use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromAttendanceMonthApproval;
use App\Domain\EventSourcing\CommandBus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-A011: attendance_month.approved を受けてバックオフィスタスクを自動作成する
 * (ExpenseClaimドメインの CreateBackOfficeTaskOnExpenseClaimApprovalReactor と同じパターン)。
 */
class CreateBackOfficeTaskOnAttendanceMonthApprovalReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onAttendanceMonthApproved(AttendanceMonthApproved $event): void
    {
        $this->commandBus->dispatch(new CreateBackOfficeTaskFromAttendanceMonthApproval($event->aggregateRootUuid()));
    }
}
