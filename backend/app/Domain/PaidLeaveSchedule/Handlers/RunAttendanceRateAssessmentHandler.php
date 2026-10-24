<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;

/**
 * @implements CommandHandler<RunAttendanceRateAssessment>
 */
class RunAttendanceRateAssessmentHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof RunAttendanceRateAssessment);

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor($command->userId))->forUser($command->userId)
            ->runAttendanceRateAssessment(
                scheduleEntryId: $command->scheduleEntryId,
                assessmentId: $command->assessmentId,
                periodStart: $command->periodStart,
                periodEnd: $command->periodEnd,
                denominatorDays: $command->denominatorDays,
                attendanceDays: $command->attendanceDays,
                excludedDays: $command->excludedDays,
                attendanceRate: $command->attendanceRate,
                policyVersion: $command->policyVersion,
                automaticResult: $command->automaticResult,
                deemedAttendanceDays: $command->deemedAttendanceDays,
            )
            ->persist();

        return null;
    }
}
