<?php

namespace App\Domain\PaidLeaveSchedule\Projectors;

use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleAssessmentOverridden;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleAssessmentRecorded;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryCancelled;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryCreated;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryGranted;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryManuallyEdited;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntrySuperseded;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use App\Models\PaidLeaveScheduleEntry;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * `PaidLeaveScheduleAggregate`(AggregateId = userIdから決定的に導出した別UUID、spec.md論点14)
 * のイベントから
 * `paid_leave_schedule_entries`(Projection Table)を更新する。CLAUDE.md原則2
 * (Projectionは再生成可能な派生データ)に従い、すべての更新はイベントのプロパティのみから
 * 冪等な`updateOrCreate`/`update`で行い、`event-sourcing:replay`によるProjection再生成に
 * そのまま対応する。
 */
class PaidLeaveScheduleEntryProjector extends Projector
{
    public function onPaidLeaveScheduleEntryCreated(PaidLeaveScheduleEntryCreated $event): void
    {
        PaidLeaveScheduleEntry::query()->updateOrCreate(
            ['id' => $event->entryId],
            [
                'user_id' => $event->userId,
                'scheduled_on' => $event->scheduledOn,
                'category' => $event->category,
                'candidate_grant_days' => $event->candidateGrantDays,
                'status' => ScheduleEntryStatus::SCHEDULED,
            ],
        );
    }

    /**
     * 個別修正済みで新算出結果と食い違った場合(`pushedToNeedsReview`)は、区分・候補日数は
     * 変更せずステータスのみNeedsReviewへ強制遷移させる(spec.md論点7)。それ以外は
     * 新しい算出結果へ置き換え、古いAssessmentは前提が変わっているためクリアして
     * Scheduledへ戻す(`PaidLeaveScheduleAggregate::applyPaidLeaveScheduleEntrySuperseded`と
     * 同じ規則)。
     */
    public function onPaidLeaveScheduleEntrySuperseded(PaidLeaveScheduleEntrySuperseded $event): void
    {
        $entry = PaidLeaveScheduleEntry::query()->find($event->entryId);

        if ($entry === null) {
            return;
        }

        if ($event->pushedToNeedsReview) {
            $entry->update(['status' => ScheduleEntryStatus::NEEDS_REVIEW]);

            return;
        }

        $entry->update([
            'category' => $event->newCategory,
            'candidate_grant_days' => $event->newCandidateGrantDays,
            'status' => ScheduleEntryStatus::SCHEDULED,
            'assessment_period_start' => null,
            'assessment_period_end' => null,
            'assessment_denominator_days' => null,
            'assessment_attendance_days' => null,
            'assessment_excluded_days' => null,
            'assessment_attendance_rate' => null,
            'assessment_policy_version' => null,
            'assessment_automatic_result' => null,
            'assessment_final_result' => null,
            'assessment_override_reason' => null,
        ]);
    }

    /**
     * 既にOverride済み(`manual_override_by_user_id`が入っている)の場合、導出ステータスは
     * 既存の`assessment_final_result`を維持し、新しい自動判定結果に引きずられて上書きしない
     * (`PaidLeaveScheduleAggregate::applyPaidLeaveScheduleAssessmentRecorded`と同じ規則)。
     */
    public function onPaidLeaveScheduleAssessmentRecorded(PaidLeaveScheduleAssessmentRecorded $event): void
    {
        $entry = PaidLeaveScheduleEntry::query()->find($event->entryId);

        if ($entry === null) {
            return;
        }

        $hasOverride = $entry->manual_override_by_user_id !== null;
        $finalResult = $hasOverride ? ($entry->assessment_final_result ?? $event->automaticResult) : $event->automaticResult;

        $entry->update([
            'assessment_period_start' => $event->periodStart,
            'assessment_period_end' => $event->periodEnd,
            'assessment_denominator_days' => $event->denominatorDays,
            'assessment_attendance_days' => $event->attendanceDays,
            'assessment_excluded_days' => $event->excludedDays,
            'assessment_attendance_rate' => $event->attendanceRate,
            'assessment_policy_version' => $event->policyVersion,
            'assessment_automatic_result' => $event->automaticResult,
            'assessment_final_result' => $finalResult,
            'assessment_override_reason' => $hasOverride ? $entry->assessment_override_reason : null,
            'status' => $this->statusFromResult($finalResult),
        ]);
    }

    public function onPaidLeaveScheduleAssessmentOverridden(PaidLeaveScheduleAssessmentOverridden $event): void
    {
        $entry = PaidLeaveScheduleEntry::query()->find($event->entryId);

        if ($entry === null) {
            return;
        }

        $entry->update([
            'manual_override_reason' => $event->reason,
            'manual_override_by_user_id' => $event->byUserId,
            'manual_override_at' => $event->at,
            'assessment_final_result' => $event->finalResult,
            'assessment_override_reason' => $event->reason,
            'status' => $this->statusFromResult($event->finalResult),
        ]);
    }

    public function onPaidLeaveScheduleEntryManuallyEdited(PaidLeaveScheduleEntryManuallyEdited $event): void
    {
        $entry = PaidLeaveScheduleEntry::query()->find($event->entryId);

        if ($entry === null) {
            return;
        }

        $attributes = [
            'manual_override_reason' => $event->reason,
            'manual_override_by_user_id' => $event->byUserId,
            'manual_override_at' => $event->at,
        ];

        if ($event->category !== null) {
            $attributes['category'] = $event->category;
        }

        if ($event->candidateGrantDays !== null) {
            $attributes['candidate_grant_days'] = $event->candidateGrantDays;
        }

        $entry->update($attributes);
    }

    public function onPaidLeaveScheduleEntryGranted(PaidLeaveScheduleEntryGranted $event): void
    {
        PaidLeaveScheduleEntry::query()->whereKey($event->entryId)->update([
            'status' => ScheduleEntryStatus::GRANTED,
            'granted_paid_leave_grant_id' => $event->grantId,
        ]);
    }

    public function onPaidLeaveScheduleEntryCancelled(PaidLeaveScheduleEntryCancelled $event): void
    {
        PaidLeaveScheduleEntry::query()->whereKey($event->entryId)->update([
            'status' => ScheduleEntryStatus::CANCELLED,
        ]);
    }

    /**
     * 判定文字列(Eligible|NotEligible|NeedsReview)をそのままステータスとして採用する
     * (`PaidLeaveScheduleAggregate::statusFromResult`と同じ規則)。
     */
    private function statusFromResult(string $result): string
    {
        return match ($result) {
            ScheduleEntryStatus::ELIGIBLE, ScheduleEntryStatus::NOT_ELIGIBLE, ScheduleEntryStatus::NEEDS_REVIEW => $result,
            default => ScheduleEntryStatus::NEEDS_REVIEW,
        };
    }
}
