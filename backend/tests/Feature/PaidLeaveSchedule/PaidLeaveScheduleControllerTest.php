<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Support\GrantCategory;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 付与予定(Schedule)管理APIのHTTPテスト
 * (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md Phase D)。
 */
class PaidLeaveScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function hrUser(): User
    {
        $hr = User::factory()->create();
        $role = Role::query()->firstOrCreate(['code' => Role::HR_STAFF], ['name' => 'HR Staff', 'is_system' => true, 'status' => 'active']);
        $this->assignRole($hr, $role);

        return $hr;
    }

    private function createEntry(User $user, string $scheduledOn, string $status, ?string $overrideByUserId = null): PaidLeaveScheduleEntry
    {
        $entryId = (string) Str::uuid();

        $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id))
            ->createEntry($user->id, $entryId, $scheduledOn, GrantCategory::REGULAR, 11.0);

        if ($status !== ScheduleEntryStatus::SCHEDULED) {
            $aggregate->recordAssessment(
                entryId: $entryId,
                periodStart: '2025-10-01',
                periodEnd: '2026-09-30',
                denominatorDays: 200,
                attendanceDays: $status === ScheduleEntryStatus::NOT_ELIGIBLE ? 100 : 190,
                excludedDays: 0,
                attendanceRate: $status === ScheduleEntryStatus::NOT_ELIGIBLE ? 50.0 : 95.0,
                policyVersion: 'v1',
                automaticResult: $status,
            );
        }

        $aggregate->persist();

        if ($overrideByUserId !== null) {
            $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
            $aggregate->manuallyEditEntry(
                entryId: $entryId,
                category: null,
                candidateGrantDays: 15.0,
                reason: '特別加算',
                byUserId: $overrideByUserId,
                at: Carbon::now()->toIso8601String(),
            );
            $aggregate->persist();

            // 個別修正後に新算出結果と食い違わせてNeedsReviewへ押し出す(「変更あり」条件)。
            $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($user->id));
            $aggregate->supersedeEntry($entryId, 'work_style変更', GrantCategory::PROPORTIONAL, 7.0);
            $aggregate->persist();
        }

        return PaidLeaveScheduleEntry::query()->findOrFail($entryId);
    }

    public function test_index_requires_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->getJson('/api/paid-leave/schedule-entries');

        $response->assertForbidden();
    }

    public function test_index_returns_all_entries_by_default(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::ELIGIBLE);
        $this->createEntry($employee, '2026-11-01', ScheduleEntryStatus::NOT_ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->getJson('/api/paid-leave/schedule-entries');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_filters_by_status(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $eligible = $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::ELIGIBLE);
        $this->createEntry($employee, '2026-11-01', ScheduleEntryStatus::NOT_ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->getJson('/api/paid-leave/schedule-entries?status=eligible');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($eligible->id, $data[0]['id']);
    }

    public function test_index_changed_filter_surfaces_conflicting_manual_overrides(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $admin = User::factory()->create();

        $changed = $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::SCHEDULED, overrideByUserId: $admin->id);
        $this->createEntry($employee, '2026-11-01', ScheduleEntryStatus::ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->getJson('/api/paid-leave/schedule-entries?status=changed');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($changed->id, $data[0]['id']);
        $this->assertTrue($data[0]['needs_review_due_to_conflict']);
    }

    public function test_index_filters_by_scheduled_on_from(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::ELIGIBLE);
        $later = $this->createEntry($employee, '2026-12-01', ScheduleEntryStatus::ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->getJson('/api/paid-leave/schedule-entries?scheduled_on_from=2026-11-01');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($later->id, $data[0]['id']);
    }

    public function test_index_filters_by_scheduled_on_to(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $earlier = $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::ELIGIBLE);
        $this->createEntry($employee, '2026-12-01', ScheduleEntryStatus::ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->getJson('/api/paid-leave/schedule-entries?scheduled_on_to=2026-11-01');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($earlier->id, $data[0]['id']);
    }

    public function test_index_filters_by_scheduled_on_range(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $this->createEntry($employee, '2026-09-01', ScheduleEntryStatus::ELIGIBLE);
        $inRange = $this->createEntry($employee, '2026-10-15', ScheduleEntryStatus::ELIGIBLE);
        $this->createEntry($employee, '2026-12-01', ScheduleEntryStatus::ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->getJson('/api/paid-leave/schedule-entries?scheduled_on_from=2026-10-01&scheduled_on_to=2026-11-01');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($inRange->id, $data[0]['id']);
    }

    public function test_index_rejects_invalid_scheduled_on_range(): void
    {
        $hr = $this->hrUser();

        $this->actingAs($hr);
        $response = $this->getJson('/api/paid-leave/schedule-entries?scheduled_on_from=2026-11-01&scheduled_on_to=2026-10-01');

        $response->assertJsonValidationErrors(['scheduled_on_to']);
    }

    public function test_index_combines_scheduled_on_range_with_status_filter(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $match = $this->createEntry($employee, '2026-10-15', ScheduleEntryStatus::ELIGIBLE);
        $this->createEntry($employee, '2026-10-20', ScheduleEntryStatus::NOT_ELIGIBLE);
        $this->createEntry($employee, '2026-12-01', ScheduleEntryStatus::ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->getJson(
            '/api/paid-leave/schedule-entries?status=eligible&scheduled_on_from=2026-10-01&scheduled_on_to=2026-11-01',
        );

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($match->id, $data[0]['id']);
    }

    public function test_show_returns_assessment_breakdown(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $entry = $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->getJson("/api/paid-leave/schedule-entries/{$entry->id}");

        $response->assertOk();
        $response->assertJsonPath('id', $entry->id);
        $response->assertJsonPath('assessment.denominator_days', 200);
        $response->assertJsonPath('assessment.policy_version', 'v1');
    }

    public function test_reassess_updates_automatic_result(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create(['hire_date' => '2024-01-01']);
        $entry = $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::SCHEDULED);

        $this->actingAs($hr);
        $response = $this->postJson("/api/paid-leave/schedule-entries/{$entry->id}/reassess");

        $response->assertOk();
        // データ不足(EmployeeCalendarEntryが無い)ため、AttendanceRateAssessorはNeedsReviewを返す。
        $response->assertJsonPath('assessment.automatic_result', ScheduleEntryStatus::NEEDS_REVIEW);
    }

    public function test_override_requires_reason(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $entry = $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::NOT_ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->postJson("/api/paid-leave/schedule-entries/{$entry->id}/override", [
            'final_result' => ScheduleEntryStatus::ELIGIBLE,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_override_rejects_invalid_final_result(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $entry = $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::NOT_ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->postJson("/api/paid-leave/schedule-entries/{$entry->id}/override", [
            'final_result' => ScheduleEntryStatus::NEEDS_REVIEW,
            'reason' => '理由',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['final_result']);
    }

    public function test_override_sets_final_result_and_reason(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $entry = $this->createEntry($employee, '2026-10-01', ScheduleEntryStatus::NOT_ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->postJson("/api/paid-leave/schedule-entries/{$entry->id}/override", [
            'final_result' => ScheduleEntryStatus::ELIGIBLE,
            'reason' => '育休からの復帰を確認済み',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', ScheduleEntryStatus::ELIGIBLE);
        $response->assertJsonPath('assessment.final_result', ScheduleEntryStatus::ELIGIBLE);
        $response->assertJsonPath('assessment.override_reason', '育休からの復帰を確認済み');
    }

    public function test_apply_grants_processes_only_eligible_entries_and_reports_per_entry_results(): void
    {
        $hr = $this->hrUser();
        $employeeA = User::factory()->create(['hire_date' => '2024-01-01']);
        $employeeB = User::factory()->create(['hire_date' => '2024-01-01']);

        $eligibleA = $this->createEntry($employeeA, '2026-10-01', ScheduleEntryStatus::ELIGIBLE);
        $eligibleB = $this->createEntry($employeeB, '2026-10-05', ScheduleEntryStatus::ELIGIBLE);
        $notEligible = $this->createEntry($employeeA, '2026-11-01', ScheduleEntryStatus::NOT_ELIGIBLE);

        $this->actingAs($hr);
        $response = $this->postJson('/api/paid-leave/schedule-entries/apply-grants', [
            'entry_ids' => [$eligibleA->id, $eligibleB->id, $notEligible->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success_count', 2);
        $response->assertJsonPath('failure_count', 1);

        $results = collect($response->json('results'))->keyBy('entry_id');
        $this->assertSame('granted', $results[$eligibleA->id]['status']);
        $this->assertSame('granted', $results[$eligibleB->id]['status']);
        $this->assertSame('failed', $results[$notEligible->id]['status']);

        $this->assertSame(ScheduleEntryStatus::GRANTED, PaidLeaveScheduleEntry::query()->findOrFail($eligibleA->id)->status);
        $this->assertSame(ScheduleEntryStatus::GRANTED, PaidLeaveScheduleEntry::query()->findOrFail($eligibleB->id)->status);
        $this->assertSame(ScheduleEntryStatus::NOT_ELIGIBLE, PaidLeaveScheduleEntry::query()->findOrFail($notEligible->id)->status);
    }
}
