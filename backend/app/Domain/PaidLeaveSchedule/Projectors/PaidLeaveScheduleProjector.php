<?php

namespace App\Domain\PaidLeaveSchedule\Projectors;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleAssessmentOverridden;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleAssessmentRecorded;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryCancelled;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryCreated;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryGranted;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryManuallyEdited;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntrySuperseded;
use App\Models\PaidLeaveScheduleAssessment;
use App\Models\PaidLeaveScheduleEntry;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * `paid_leave_schedule.*`イベントから`paid_leave_schedule_entries`/
 * `paid_leave_schedule_assessments`を作成・更新する。主キーがコマンド側生成のUUIDのため、
 * 行の新規作成(entry_created / assessment_recorded)自体もこのProjectorが担う
 * (.claude/skills/add-projection「集約ルートのUUID化」参照)。冪等に書く。
 */
class PaidLeaveScheduleProjector extends Projector
{
    public function onPaidLeaveScheduleEntryCreated(PaidLeaveScheduleEntryCreated $event): void
    {
        PaidLeaveScheduleEntry::query()->updateOrCreate(
            ['id' => $event->scheduleEntryId],
            [
                'user_id' => $event->aggregateRootUuid(),
                'scheduled_on' => $event->scheduledOn,
                'category' => $event->category,
                'candidate_grant_days' => $event->candidateGrantDays,
                'status' => $event->isDeterminate ? PaidLeaveScheduleEntry::STATUS_SCHEDULED : PaidLeaveScheduleEntry::STATUS_NEEDS_REVIEW,
            ],
        );
    }

    public function onPaidLeaveScheduleEntrySuperseded(PaidLeaveScheduleEntrySuperseded $event): void
    {
        PaidLeaveScheduleEntry::query()->whereKey($event->scheduleEntryId)->update([
            'status' => PaidLeaveScheduleEntry::STATUS_CANCELLED,
            'cancelled_reason' => $event->reason,
        ]);
    }

    public function onPaidLeaveScheduleAssessmentRecorded(PaidLeaveScheduleAssessmentRecorded $event): void
    {
        PaidLeaveScheduleAssessment::query()->updateOrCreate(
            ['id' => $event->assessmentId],
            [
                'schedule_entry_id' => $event->scheduleEntryId,
                'period_start' => $event->periodStart,
                'period_end' => $event->periodEnd,
                'denominator_days' => $event->denominatorDays,
                'attendance_days' => $event->attendanceDays,
                'excluded_days' => $event->excludedDays,
                'attendance_rate' => $event->attendanceRate,
                'policy_version' => $event->policyVersion,
                'automatic_result' => $event->automaticResult,
                'final_result' => $event->automaticResult,
            ],
        );

        PaidLeaveScheduleEntry::query()->whereKey($event->scheduleEntryId)->update([
            'latest_assessment_id' => $event->assessmentId,
            'status' => $event->automaticResult,
        ]);
    }

    public function onPaidLeaveScheduleAssessmentOverridden(PaidLeaveScheduleAssessmentOverridden $event): void
    {
        PaidLeaveScheduleAssessment::query()->whereKey($event->assessmentId)->update([
            'final_result' => $event->finalResult,
            'override_reason' => $event->reason,
            'overridden_by_user_id' => $event->operatorUserId,
        ]);

        PaidLeaveScheduleEntry::query()->whereKey($event->scheduleEntryId)->update([
            'status' => $event->finalResult,
        ]);
    }

    public function onPaidLeaveScheduleEntryManuallyEdited(PaidLeaveScheduleEntryManuallyEdited $event): void
    {
        $updates = [];

        if (array_key_exists('scheduledOn', $event->changes)) {
            $updates['scheduled_on'] = $event->changes['scheduledOn'];
        }
        if (array_key_exists('category', $event->changes)) {
            $updates['category'] = $event->changes['category'];
        }
        if (array_key_exists('candidateGrantDays', $event->changes)) {
            $updates['candidate_grant_days'] = $event->changes['candidateGrantDays'];
        }

        $updates['is_manually_overridden'] = true;
        $updates['manual_override_reason'] = $event->reason;
        $updates['manual_override_by_user_id'] = $event->operatorUserId;
        $updates['manual_override_at'] = $event->createdAt();

        PaidLeaveScheduleEntry::query()->whereKey($event->scheduleEntryId)->update($updates);
    }

    public function onPaidLeaveScheduleEntryGranted(PaidLeaveScheduleEntryGranted $event): void
    {
        PaidLeaveScheduleEntry::query()->whereKey($event->scheduleEntryId)->update([
            'status' => PaidLeaveScheduleEntry::STATUS_GRANTED,
            'grant_id' => $event->grantId,
        ]);
    }

    public function onPaidLeaveScheduleEntryCancelled(PaidLeaveScheduleEntryCancelled $event): void
    {
        PaidLeaveScheduleEntry::query()->whereKey($event->scheduleEntryId)->update([
            'status' => PaidLeaveScheduleEntry::STATUS_CANCELLED,
            'cancelled_reason' => $event->reason,
        ]);
    }
}
