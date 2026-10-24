<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Handlers\ApplyScheduledGrantsHandler;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /**
     * @return array{0: string, 1: string} [名目6の行ID, 名目18の行ID](同じ社員・同じ日、どちらもEligible)
     */
    private function createSameDayEligibleEntries(User $user): array
    {
        $id6 = (string) Str::uuid();
        $id18 = (string) Str::uuid();
        // 18を先に作る(並びが作成順ではなく名目の月数順であることを確かめる)。
        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => $id18, 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0, 'nominalServiceMonths' => 18],
                ['entryId' => $id6, 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
            ],
        ));

        foreach ([$id18, $id6] as $entryId) {
            $this->bus()->dispatch(new RunAttendanceRateAssessment(
                userId: $user->id,
                scheduleEntryId: $entryId,
                assessmentId: (string) Str::uuid(),
                periodStart: '2025-04-01',
                periodEnd: '2026-04-01',
                denominatorDays: 20,
                attendanceDays: 18,
                excludedDays: 0,
                attendanceRate: 90.0,
                policyVersion: 'v1',
                automaticResult: PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
            ));
        }

        return [$id6, $id18];
    }

    public function test_index_returns_nominal_service_months_and_orders_same_day_rows_by_it(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        [$id6, $id18] = $this->createSameDayEligibleEntries($user);

        $response = $this->actingAs($admin)->getJson('/api/paid-leave/schedule-entries');

        $response->assertOk();
        $this->assertSame([$id6, $id18], array_column($response->json('data'), 'id'));
        $this->assertSame([6, 18], array_column($response->json('data'), 'nominal_service_months'));
    }

    public function test_bulk_grant_grants_same_day_entries_in_nominal_months_order_with_ids_derived_from_entry_ids(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        [$id6, $id18] = $this->createSameDayEligibleEntries($user);

        // 名目の大きい方を先に渡しても、名目の小さい順に付与される。
        $response = $this->actingAs($admin)->postJson('/api/paid-leave/schedule-entries/bulk-grant', [
            'schedule_entry_ids' => [$id18, $id6],
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('success_count'));

        $grants = PaidLeaveGrant::query()->where('user_id', $user->id)->get();
        $this->assertCount(2, $grants);
        $expected6 = ApplyScheduledGrantsHandler::grantIdFor($id6);
        $expected18 = ApplyScheduledGrantsHandler::grantIdFor($id18);
        $this->assertEqualsCanonicalizing([$expected6, $expected18], $grants->pluck('id')->all());

        $stored = DB::table('stored_events')
            ->where('event_class', 'paid_leave_account.grant_created')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => json_decode($row->event_properties, true)['grantId'] ?? null)
            ->all();
        $this->assertSame([$expected6, $expected18], $stored);

        $this->assertSame($expected6, PaidLeaveScheduleEntry::query()->findOrFail($id6)->grant_id);
        $this->assertSame($expected18, PaidLeaveScheduleEntry::query()->findOrFail($id18)->grant_id);
        $this->assertSame(
            [10.0, 11.0],
            $grants->sortBy('granted_days')->map(fn ($g) => (float) $g->granted_days)->values()->all(),
        );
    }

    public function test_granting_the_same_entry_again_is_rejected_by_the_account(): void
    {
        $user = User::factory()->create();
        [$id6, $id18] = $this->createSameDayEligibleEntries($user);

        // まとめて付与する2件は組で付与する(片方だけは付与しない)。
        $this->bus()->dispatch(new ApplyScheduledGrants(userId: $user->id, scheduleEntryIds: [$id6, $id18], operatorUserId: $user->id));

        // 予定の行の更新に失敗した想定で、行を再度Eligibleにはできないが、同じ付与IDでの付与は口座が拒否する。
        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new GrantPaidLeave(
            userId: $user->id,
            grantedOn: '2026-04-01',
            expiresOn: '2028-04-01',
            grantedDays: 10.0,
            grantReason: null,
            source: 'scheduled_batch',
            grantId: ApplyScheduledGrantsHandler::grantIdFor($id6),
        ));
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

    public function test_admin_can_patch_category_and_candidate_grant_days(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId);

        $response = $this->actingAs($admin)->patchJson("/api/paid-leave/schedule-entries/{$entryId}", [
            'category' => 'proportional',
            'candidate_grant_days' => 7.5,
            'reason' => '勤務形態変更に伴う個別調整',
        ]);

        $response->assertOk();
        $this->assertSame('proportional', $response->json('data.category'));
        $this->assertSame(7.5, $response->json('data.candidate_grant_days'));
        $this->assertTrue($response->json('data.is_manually_overridden'));

        $entry = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame('proportional', $entry->category);
        $this->assertSame(7.5, $entry->candidate_grant_days);
        $this->assertTrue($entry->is_manually_overridden);
        $this->assertSame('勤務形態変更に伴う個別調整', $entry->manual_override_reason);

        $recorded = DB::table('stored_events')
            ->where('event_class', 'paid_leave_schedule.entry_manually_edited')
            ->get();
        $this->assertCount(1, $recorded);
        $this->assertSame(
            ['category' => 'proportional', 'candidateGrantDays' => 7.5],
            json_decode((string) $recorded->first()->event_properties, true)['changes'],
        );

        $this->assertSame([$entryId], $this->listedEntryIds($admin, ['filter' => 'changed']));
    }

    public function test_patch_rejects_invalid_input_without_recording_events(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId);
        $url = "/api/paid-leave/schedule-entries/{$entryId}";

        $missingReason = $this->actingAs($admin)->patchJson($url, [
            'category' => 'proportional',
        ]);
        $missingReason->assertUnprocessable();
        $missingReason->assertJsonValidationErrors(['reason']);

        $missingEditableFields = $this->actingAs($admin)->patchJson($url, [
            'reason' => '項目なし',
        ]);
        $missingEditableFields->assertUnprocessable();
        $missingEditableFields->assertJsonValidationErrors(['category', 'candidate_grant_days']);

        $tooSmallDays = $this->actingAs($admin)->patchJson($url, [
            'candidate_grant_days' => 0.3,
            'reason' => '刻み違反',
        ]);
        $tooSmallDays->assertUnprocessable();
        $tooSmallDays->assertJsonValidationErrors(['candidate_grant_days']);

        $unknownCategory = $this->actingAs($admin)->patchJson($url, [
            'category' => 'needs_review',
            'reason' => '区分違反',
        ]);
        $unknownCategory->assertUnprocessable();
        $unknownCategory->assertJsonValidationErrors(['category']);

        $this->assertSame(0, DB::table('stored_events')->where('event_class', 'paid_leave_schedule.entry_manually_edited')->count());
        $this->assertFalse(PaidLeaveScheduleEntry::query()->findOrFail($entryId)->is_manually_overridden);
    }

    public function test_patch_ignores_scheduled_on(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId, '2026-10-01');

        $response = $this->actingAs($admin)->patchJson("/api/paid-leave/schedule-entries/{$entryId}", [
            'scheduled_on' => '2026-10-05',
            'candidate_grant_days' => 8,
            'reason' => '付与予定日は変更しない',
        ]);

        $response->assertOk();
        $this->assertSame('2026-10-01', $response->json('data.scheduled_on'));
        $this->assertSame(8.0, (float) $response->json('data.candidate_grant_days'));

        $entry = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame('2026-10-01', $entry->scheduled_on->toDateString());
        $this->assertSame(8.0, $entry->candidate_grant_days);
    }

    public function test_patch_on_granted_entry_is_rejected(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId);
        $this->assessEntry($user->id, $entryId, PaidLeaveScheduleAggregate::STATUS_ELIGIBLE);

        $this->bus()->dispatch(new ApplyScheduledGrants(
            userId: $user->id,
            scheduleEntryIds: [$entryId],
            operatorUserId: $admin->id,
        ));
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_GRANTED, PaidLeaveScheduleEntry::query()->findOrFail($entryId)->status);

        $response = $this->actingAs($admin)->patchJson("/api/paid-leave/schedule-entries/{$entryId}", [
            'candidate_grant_days' => 9,
            'reason' => '付与後の修正',
        ]);

        $response->assertUnprocessable();
        $entry = PaidLeaveScheduleEntry::query()->findOrFail($entryId);
        $this->assertSame(10.0, $entry->candidate_grant_days);
        $this->assertFalse($entry->is_manually_overridden);
    }

    public function test_employee_cannot_patch_schedule_entry(): void
    {
        $employee = User::factory()->create();
        $user = User::factory()->create();
        $entryId = (string) Str::uuid();
        $this->createEntry($user->id, $entryId);

        $this->actingAs($employee)->patchJson("/api/paid-leave/schedule-entries/{$entryId}", [
            'candidate_grant_days' => 9,
            'reason' => '権限なし',
        ])->assertForbidden();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function assessEntry(string $userId, string $entryId, string $automaticResult): void
    {
        $this->bus()->dispatch(new RunAttendanceRateAssessment(
            userId: $userId,
            scheduleEntryId: $entryId,
            assessmentId: (string) Str::uuid(),
            periodStart: '2026-04-01',
            periodEnd: '2026-10-01',
            denominatorDays: 20,
            attendanceDays: $automaticResult === PaidLeaveScheduleAggregate::STATUS_ELIGIBLE ? 18 : 10,
            excludedDays: 0,
            attendanceRate: $automaticResult === PaidLeaveScheduleAggregate::STATUS_ELIGIBLE ? 90.0 : 50.0,
            policyVersion: 'v1',
            automaticResult: $automaticResult,
        ));
    }

    /**
     * @param  array<string, string>  $query
     * @return array<int, string>
     */
    private function listedEntryIds(User $admin, array $query = []): array
    {
        $response = $this->actingAs($admin)->getJson('/api/paid-leave/schedule-entries?'.http_build_query($query));
        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    }

    public function test_user_name_filter_matches_partial_name_and_treats_wildcards_literally(): void
    {
        $admin = $this->admin();
        $percentUser = User::factory()->create(['name' => '100%田中 太郎']);
        $plainUser = User::factory()->create(['name' => '1000田中 花子']);
        $percentEntryId = (string) Str::uuid();
        $plainEntryId = (string) Str::uuid();
        $this->createEntry($percentUser->id, $percentEntryId);
        $this->createEntry($plainUser->id, $plainEntryId, '2026-10-02');

        // `%`・`_`・`\`は文字どおりに一致させる(ワイルドカードとして扱わない)。
        $this->assertSame([$percentEntryId], $this->listedEntryIds($admin, ['user_name' => '%']));
        $this->assertSame([$percentEntryId], $this->listedEntryIds($admin, ['user_name' => '0%田']));
        $this->assertSame([], $this->listedEntryIds($admin, ['user_name' => '_']));
        $this->assertSame([], $this->listedEntryIds($admin, ['user_name' => '\\']));
        $this->assertEqualsCanonicalizing(
            [$percentEntryId, $plainEntryId],
            $this->listedEntryIds($admin, ['user_name' => '田中']),
        );
    }

    public function test_scheduled_on_range_includes_both_ends(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $ids = [];
        foreach (['2026-03-31', '2026-04-01', '2026-04-30', '2026-05-01'] as $date) {
            $ids[$date] = (string) Str::uuid();
            $this->createEntry($user->id, $ids[$date], $date);
        }

        $this->assertSame(
            [$ids['2026-04-01']],
            $this->listedEntryIds($admin, ['scheduled_on_from' => '2026-04-01', 'scheduled_on_to' => '2026-04-01']),
        );
        $this->assertEqualsCanonicalizing(
            [$ids['2026-04-01'], $ids['2026-04-30']],
            $this->listedEntryIds($admin, ['scheduled_on_from' => '2026-04-01', 'scheduled_on_to' => '2026-04-30']),
        );
        $this->assertEqualsCanonicalizing(
            [$ids['2026-04-01'], $ids['2026-04-30'], $ids['2026-05-01']],
            $this->listedEntryIds($admin, ['scheduled_on_from' => '2026-04-01']),
        );
    }

    public function test_status_filter_user_name_and_scheduled_on_range_are_combined(): void
    {
        $admin = $this->admin();
        $alpha = User::factory()->create(['name' => 'Alpha 太郎']);
        $beta = User::factory()->create(['name' => 'Beta 次郎']);
        $alphaEligibleId = (string) Str::uuid();
        $alphaNotEligibleId = (string) Str::uuid();
        $betaEligibleId = (string) Str::uuid();
        $this->createEntry($alpha->id, $alphaEligibleId, '2026-10-01');
        $this->assessEntry($alpha->id, $alphaEligibleId, PaidLeaveScheduleAggregate::STATUS_ELIGIBLE);
        $this->createEntry($alpha->id, $alphaNotEligibleId, '2026-10-02');
        $this->assessEntry($alpha->id, $alphaNotEligibleId, PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE);
        $this->createEntry($beta->id, $betaEligibleId, '2026-10-01');
        $this->assessEntry($beta->id, $betaEligibleId, PaidLeaveScheduleAggregate::STATUS_ELIGIBLE);

        $this->assertSame(
            [$alphaEligibleId],
            $this->listedEntryIds($admin, ['filter' => 'eligible', 'user_name' => 'Alpha']),
        );
        $this->assertSame(
            [],
            $this->listedEntryIds($admin, ['filter' => 'not_eligible', 'user_name' => 'Beta']),
        );
        $this->assertSame(
            [],
            $this->listedEntryIds($admin, ['filter' => 'eligible', 'scheduled_on_from' => '2026-10-02']),
        );
        $this->assertSame(
            [$alphaEligibleId],
            $this->listedEntryIds($admin, ['filter' => 'eligible', 'user_name' => 'Alpha', 'scheduled_on_from' => '2026-10-01', 'scheduled_on_to' => '2026-10-01']),
        );
    }

    public function test_scheduled_on_to_before_from_and_unknown_filter_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/api/paid-leave/schedule-entries?'.http_build_query(['scheduled_on_from' => '2026-05-01', 'scheduled_on_to' => '2026-04-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_on_to']);

        $this->actingAs($admin)
            ->getJson('/api/paid-leave/schedule-entries?filter=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter']);
    }

    public function test_overdue_filter_returns_unfinalized_entries_scheduled_up_to_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00'));
        $admin = $this->admin();
        $user = User::factory()->create();

        // 今日・明日の境界(今日は含み、明日は含まない)。
        $todayScheduledId = (string) Str::uuid();
        $tomorrowScheduledId = (string) Str::uuid();
        $this->createEntry($user->id, $todayScheduledId, '2026-10-20');
        $this->createEntry($user->id, $tomorrowScheduledId, '2026-10-21');

        // 過去の未確定の状態(含む)。
        $pastScheduledId = (string) Str::uuid();
        $this->createEntry($user->id, $pastScheduledId, '2026-09-01');

        // AssessmentPendingは現行のCommandからは遷移しないため、Projectionへ直接作る
        // (RebuildPaidLeaveScheduleCommandTest等と同じ既存のテストの作り方)。
        $pastAssessmentPendingId = (string) Str::uuid();
        PaidLeaveScheduleEntry::query()->create([
            'id' => $pastAssessmentPendingId,
            'user_id' => $user->id,
            'scheduled_on' => '2026-09-02',
            'category' => 'normal',
            'candidate_grant_days' => 10.0,
            'status' => PaidLeaveScheduleAggregate::STATUS_ASSESSMENT_PENDING,
        ]);

        $pastEligibleId = (string) Str::uuid();
        $this->createEntry($user->id, $pastEligibleId, '2026-09-03');
        $this->assessEntry($user->id, $pastEligibleId, PaidLeaveScheduleAggregate::STATUS_ELIGIBLE);

        $pastNeedsReviewId = (string) Str::uuid();
        $this->bus()->dispatch(new EnsureFutureScheduleGenerated(
            userId: $user->id,
            candidates: [
                ['entryId' => $pastNeedsReviewId, 'scheduledOn' => '2026-09-04', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'isDeterminate' => false],
            ],
        ));

        // 除外される状態(過去でも含まない): NotEligible・Granted・Cancelled。
        $pastNotEligibleId = (string) Str::uuid();
        $this->createEntry($user->id, $pastNotEligibleId, '2026-09-05');
        $this->assessEntry($user->id, $pastNotEligibleId, PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE);

        $pastGrantedId = (string) Str::uuid();
        $this->createEntry($user->id, $pastGrantedId, '2026-09-06');
        $this->assessEntry($user->id, $pastGrantedId, PaidLeaveScheduleAggregate::STATUS_ELIGIBLE);
        $this->bus()->dispatch(new ApplyScheduledGrants(
            userId: $user->id,
            scheduleEntryIds: [$pastGrantedId],
            operatorUserId: $admin->id,
        ));

        // Cancelledは再計算で候補が無くなることで発生させる。同一社員の他の予定を巻き込まないよう別社員にする。
        $cancelUser = User::factory()->create();
        $pastCancelledId = (string) Str::uuid();
        $this->createEntry($cancelUser->id, $pastCancelledId, '2026-09-07');
        $this->bus()->dispatch(new RecalculateFutureSchedule(
            userId: $cancelUser->id,
            candidates: [],
            reason: 'テスト用の取消',
            fromDate: '2026-09-01',
        ));

        $statuses = PaidLeaveScheduleEntry::query()->pluck('status', 'id');
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_GRANTED, $statuses[$pastGrantedId]);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_CANCELLED, $statuses[$pastCancelledId]);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, $statuses[$pastNotEligibleId]);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW, $statuses[$pastNeedsReviewId]);

        $this->assertEqualsCanonicalizing(
            [$todayScheduledId, $pastScheduledId, $pastAssessmentPendingId, $pastEligibleId, $pastNeedsReviewId],
            $this->listedEntryIds($admin, ['filter' => 'overdue']),
        );
        $this->assertEqualsCanonicalizing(
            [$pastScheduledId, $pastAssessmentPendingId, $pastEligibleId, $pastNeedsReviewId],
            $this->listedEntryIds($admin, ['filter' => 'overdue', 'scheduled_on_to' => '2026-09-30']),
        );
    }
}
