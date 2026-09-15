<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;
use App\Domain\PaidLeaveSchedule\Commands\OverrideScheduleAssessment;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Models\PaidLeaveScheduleAssessment;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * `PaidLeaveScheduleProjector`(`paid_leave_schedule_entries`/`paid_leave_schedule_assessments`)
 * のCommand→Handler→Aggregate→Event→Projectorパイプライン、および
 * `event-sourcing:replay`によるProjection再生成の検証(spec.md 実装対象Phase B、
 * .claude/skills/add-projection)。
 */
class PaidLeaveScheduleProjectorTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    public function test_entry_created_populates_projection_row(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => 'e1', 'scheduledOn' => '2026-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
            ],
        ));

        $entry = PaidLeaveScheduleEntry::query()->findOrFail('e1');
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('2026-10-01', $entry->scheduled_on->toDateString());
        $this->assertSame('normal', $entry->category);
        $this->assertSame(10.0, (float) $entry->candidate_grant_days);
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $entry->status);
    }

    public function test_assessment_recorded_populates_assessment_row_and_updates_entry_status(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => 'e1', 'scheduledOn' => '2026-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
            ],
        ));

        $assessmentId = (string) Str::uuid();
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: 'e1',
            assessmentId: $assessmentId,
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: 18,
            excludedDays: 0,
            attendanceRate: 90.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
        ));

        $entry = PaidLeaveScheduleEntry::query()->findOrFail('e1');
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $entry->status);
        $this->assertSame($assessmentId, $entry->latest_assessment_id);

        $assessment = PaidLeaveScheduleAssessment::query()->findOrFail($assessmentId);
        $this->assertSame('e1', $assessment->schedule_entry_id);
        $this->assertSame(20, $assessment->denominator_days);
        $this->assertSame(18, $assessment->attendance_days);
        $this->assertSame(90.0, (float) $assessment->attendance_rate);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $assessment->automatic_result);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $assessment->final_result);
        $this->assertNull($assessment->override_reason);
    }

    public function test_assessment_overridden_updates_final_result_but_keeps_automatic_result(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => 'e1', 'scheduledOn' => '2026-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
            ],
        ));

        $assessmentId = (string) Str::uuid();
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: 'e1',
            assessmentId: $assessmentId,
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: 10,
            excludedDays: 0,
            attendanceRate: 50.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
        ));

        $operator = User::factory()->create();
        $this->bus()->dispatch(new OverrideScheduleAssessment(
            userId: $user->id,
            scheduleEntryId: 'e1',
            finalResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
            reason: '育休からの復職を考慮',
            operatorUserId: $operator->id,
        ));

        $entry = PaidLeaveScheduleEntry::query()->findOrFail('e1');
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $entry->status);

        $assessment = PaidLeaveScheduleAssessment::query()->findOrFail($assessmentId);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, $assessment->automatic_result);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $assessment->final_result);
        $this->assertSame('育休からの復職を考慮', $assessment->override_reason);
    }

    public function test_manually_edited_marks_entry_as_manually_overridden(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => 'e1', 'scheduledOn' => '2026-10-01', 'category' => 'proportional', 'candidateGrantDays' => 8.0],
            ],
        ));

        $operator = User::factory()->create();
        $this->bus()->dispatch(new ManuallyEditScheduleEntry(
            userId: $user->id,
            scheduleEntryId: 'e1',
            changes: ['candidateGrantDays' => 9.0],
            reason: '個別事情による調整',
            operatorUserId: $operator->id,
        ));

        $entry = PaidLeaveScheduleEntry::query()->findOrFail('e1');
        $this->assertTrue($entry->is_manually_overridden);
        $this->assertSame('個別事情による調整', $entry->manual_override_reason);
        $this->assertSame(9.0, (float) $entry->candidate_grant_days);
    }

    /**
     * Projectorが冪等であること、および`stored_events`から全件再生してもテーブルを
     * 空にした状態から同じ結果になることを確認する(.claude/skills/add-projectionチェックリスト)。
     */
    public function test_projection_is_rebuildable_from_stored_events(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => 'e1', 'scheduledOn' => '2026-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
            ],
        ));

        $assessmentId = (string) Str::uuid();
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: 'e1',
            assessmentId: $assessmentId,
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: 18,
            excludedDays: 0,
            attendanceRate: 90.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
        ));

        $beforeEntry = PaidLeaveScheduleEntry::query()->findOrFail('e1')->toArray();
        $beforeAssessment = PaidLeaveScheduleAssessment::query()->findOrFail($assessmentId)->toArray();

        $this->assertGreaterThan(0, EloquentStoredEvent::query()->count());

        PaidLeaveScheduleAssessment::query()->delete();
        PaidLeaveScheduleEntry::query()->delete();

        Artisan::call('event-sourcing:replay', [
            'projector' => [\App\Domain\PaidLeaveSchedule\Projectors\PaidLeaveScheduleProjector::class],
            '--force' => true,
        ]);

        $afterEntry = PaidLeaveScheduleEntry::query()->findOrFail('e1')->toArray();
        $afterAssessment = PaidLeaveScheduleAssessment::query()->findOrFail($assessmentId)->toArray();

        unset($beforeEntry['updated_at'], $beforeEntry['created_at'], $afterEntry['updated_at'], $afterEntry['created_at']);
        unset($beforeAssessment['updated_at'], $beforeAssessment['created_at'], $afterAssessment['updated_at'], $afterAssessment['created_at']);

        $this->assertSame($beforeEntry, $afterEntry);
        $this->assertSame($beforeAssessment, $afterAssessment);
    }
}
