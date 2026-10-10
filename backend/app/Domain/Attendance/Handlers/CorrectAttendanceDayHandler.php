<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\CorrectAttendanceDay;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use Illuminate\Support\Facades\DB;

/**
 * 勤怠日の補正イベント(attendance_day.corrected)を1件記録する(論点12・仕様確定事項H)。
 *
 * 冪等: 同じ補正IDのイベントが既にこの勤怠日の集約にあれば何もしない(補正の再実行で二重に記録しない)。
 *
 * @implements CommandHandler<CorrectAttendanceDay>
 */
class CorrectAttendanceDayHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof CorrectAttendanceDay);

        $alreadyRecorded = DB::table('stored_events')
            ->where('aggregate_uuid', $command->attendanceDayId)
            ->where('event_class', 'attendance_day.corrected')
            ->where('event_properties->correctionId', $command->correctionId)
            ->exists();
        if ($alreadyRecorded) {
            return null;
        }

        AttendanceDayAggregate::retrieve($command->attendanceDayId)
            ->correct(
                correctionId: $command->correctionId,
                userId: $command->userId,
                workDate: $command->workDate,
                calendarEntryId: $command->calendarEntryId,
                status: $command->status,
                source: $command->source,
                utcOffsetMinutes: $command->utcOffsetMinutes,
                actualStartAt: $command->actualStartAt,
                actualEndAt: $command->actualEndAt,
                workType: $command->workType,
                workLocationType: $command->workLocationType,
                note: $command->note,
                dayClassification: $command->dayClassification,
                breaks: $command->breaks,
                leaveSegments: $command->leaveSegments,
                dailyCalculation: $command->dailyCalculation,
                weeklyOvertimeAllocation: $command->weeklyOvertimeAllocation,
                reason: $command->reason,
                correctedByUserId: $command->correctedByUserId,
            )
            ->persist();

        return null;
    }
}
