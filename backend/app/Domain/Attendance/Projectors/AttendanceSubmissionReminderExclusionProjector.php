<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\AttendanceSubmissionReminderExcluded;
use App\Models\AttendanceSubmissionReminderExclusion;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * attendance.submission_reminder_excludedからattendance_submission_reminder_exclusionsを
 * 作成・更新する(.claude/skills/add-projection参照)。
 */
class AttendanceSubmissionReminderExclusionProjector extends Projector
{
    public function onAttendanceSubmissionReminderExcluded(AttendanceSubmissionReminderExcluded $event): void
    {
        $exclusion = AttendanceSubmissionReminderExclusion::query()->find($event->aggregateRootUuid())
            ?? new AttendanceSubmissionReminderExclusion(['id' => $event->aggregateRootUuid()]);

        $exclusion->fill([
            'user_id' => $event->userId,
            'year_month' => $event->yearMonth,
            'reason' => $event->reason,
            'excluded_by_user_id' => $event->excludedByUserId,
        ])->save();
    }
}
