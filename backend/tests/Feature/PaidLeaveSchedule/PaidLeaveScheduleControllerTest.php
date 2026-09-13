<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 有給付与予定Schedule管理API (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md Phase D)。
 */
class PaidLeaveScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $admin;
    }

    private function createEntry(string $userId, string $entryId, string $scheduledOn = '2026-10-01', string $category = 'normal', float $grantDays = 10.0): void
    {
        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $userId,
            candidates: [
                ['entryId' => $entryId, 'scheduledOn' => $scheduledOn, 'category' => $category, 'candidateGrantDays' => $grantDays],
            ],
        ));
    }

    public function test_employee_cannot_list_schedule_entries(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/paid-leave/schedule-entries')->assertForbidden();
    }

    public function test_admin_can_list_and_filter_schedule_entries(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId);

        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: $entryId,
            assessmentId: (string) Str::uuid(),
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: 18,
            excludedDays: 0,
            attendanceRate: 90.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
        ));

        $all = $this->actingAs($admin)->getJson('/api/paid-leave/schedule-entries');
        $all->assertOk();
        $this->assertCount(1, $all->json('data'));

        $eligible = $this->actingAs($admin)->getJson('/api/paid-leave/schedule-entries?filter=eligible');
        $eligible->assertOk();
        $this->assertCount(1, $eligible->json('data'));

        $notEligible = $this->actingAs($admin)->getJson('/api/paid-leave/schedule-entries?filter=not_eligible');
        $notEligible->assertOk();
        $this->assertCount(0, $notEligible->json('data'));
    }

    public function test_changed_filter_returns_manually_overridden_entries(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId);

        $this->bus()->dispatch(new ManuallyEditScheduleEntry(
            userId: $user->id,
            scheduleEntryId: $entryId,
            changes: ['candidateGrantDays' => 12.0],
            reason: '個別調整',
            operatorUserId: $admin->id,
        ));

        $response = $this->actingAs($admin)->getJson('/api/paid-leave/schedule-entries?filter=changed');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($entryId, $response->json('data.0.id'));
    }

    public function test_admin_can_view_entry_detail_with_assessment_history(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId);

        $assessmentId = (string) Str::uuid();
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: $entryId,
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

        $response = $this->actingAs($admin)->getJson("/api/paid-leave/schedule-entries/{$entryId}");
        $response->assertOk();
        $this->assertCount(1, $response->json('data.assessments'));
        $this->assertSame(20, $response->json('data.assessments.0.denominator_days'));
    }

    public function test_admin_can_reassess_entry(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['usage_start_date' => '2020-01-01']);
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId, '2026-10-01');

        $response = $this->actingAs($admin)->postJson("/api/paid-leave/schedule-entries/{$entryId}/reassess");

        $response->assertOk();
        // 出勤実績データが無い(分母日数0件)ため、要確認へ倒れる想定。
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW, $response->json('data.status'));

        $entry = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertNotNull($entry->latest_assessment_id);
    }

    public function test_override_requires_reason(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId);

        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: $entryId,
            assessmentId: (string) Str::uuid(),
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: 10,
            excludedDays: 0,
            attendanceRate: 50.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
        ));

        $missingReason = $this->actingAs($admin)->postJson("/api/paid-leave/schedule-entries/{$entryId}/override", [
            'final_result' => PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
        ]);
        $missingReason->assertUnprocessable();
        $missingReason->assertJsonValidationErrors(['reason']);

        $ok = $this->actingAs($admin)->postJson("/api/paid-leave/schedule-entries/{$entryId}/override", [
            'final_result' => PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
            'reason' => '育休からの復職を考慮',
        ]);
        $ok->assertOk();
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $ok->json('data.status'));
    }

    public function test_bulk_grant_reports_per_entry_success_and_failure(): void
    {
        $admin = $this->admin();
        $eligibleUser = User::factory()->create();
        $notEligibleUser = User::factory()->create();

        $eligibleEntryId = (string) Str::uuid();
        $this->createEntry($eligibleUser->id, $eligibleEntryId, '2026-10-01');
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $eligibleUser->id,
            scheduleEntryId: $eligibleEntryId,
            assessmentId: (string) Str::uuid(),
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: 18,
            excludedDays: 0,
            attendanceRate: 90.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
        ));

        $notEligibleEntryId = (string) Str::uuid();
        $this->createEntry($notEligibleUser->id, $notEligibleEntryId, '2026-10-01');
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $notEligibleUser->id,
            scheduleEntryId: $notEligibleEntryId,
            assessmentId: (string) Str::uuid(),
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: 10,
            excludedDays: 0,
            attendanceRate: 50.0,
            policyVersion: 'v1',
            automaticResult: PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
        ));

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/schedule-entries/bulk-grant', [
            'schedule_entry_ids' => [$eligibleEntryId, $notEligibleEntryId],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('success_count'));
        $this->assertSame(1, $response->json('failure_count'));

        $results = collect($response->json('results'))->keyBy('schedule_entry_id');
        $this->assertTrue($results[$eligibleEntryId]['success']);
        $this->assertFalse($results[$notEligibleEntryId]['success']);

        $this->assertSame(
            PaidLeaveScheduleAggregate::STATUS_GRANTED,
            PaidLeaveScheduleEntry::query()->findOrFail($eligibleEntryId)->status,
        );
        $this->assertSame(
            PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
            PaidLeaveScheduleEntry::query()->findOrFail($notEligibleEntryId)->status,
        );
    }
}
