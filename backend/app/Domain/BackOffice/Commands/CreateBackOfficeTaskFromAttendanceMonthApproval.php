<?php

namespace App\Domain\BackOffice\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A011: 月次勤怠の承認を受けてバックオフィスタスクを自動作成する。
 * attendance_month.approved イベントを受けて
 * App\Domain\Attendance\Reactors\CreateBackOfficeTaskOnAttendanceMonthApprovalReactor
 * から発行される。
 */
class CreateBackOfficeTaskFromAttendanceMonthApproval implements Command
{
    public function __construct(public readonly string $attendanceMonthId) {}
}
