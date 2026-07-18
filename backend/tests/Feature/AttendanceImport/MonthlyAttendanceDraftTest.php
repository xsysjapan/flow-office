<?php

namespace Tests\Feature\AttendanceImport;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\FieldProvenance;
use App\Models\FieldSourceType;
use App\Models\MonthlyDraftStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-R001/UC-R002: 月次勤怠下書き(docs/26-usecases-monthly-import.md)。
 */
class MonthlyAttendanceDraftTest extends TestCase
{
    use RefreshDatabase;

    private function createDraft(User $user, string $targetMonth = '2026-07'): array
    {
        $response = $this->actingAs($user)->postJson('/api/attendance/monthly-drafts', [
            'target_month' => $targetMonth,
            'source_type' => 'work_report',
        ]);
        $response->assertCreated();

        return $response->json();
    }

    private function cleanDayPayload(string $date): array
    {
        return [
            'date' => $date,
            'startTime' => '09:00',
            'endTime' => '18:00',
            'breaks' => [['startTime' => '12:00', 'endTime' => '13:00']],
            'workLocationType' => 'remote',
            'source' => FieldSourceType::USER_CONFIRMED,
        ];
    }

    public function test_bulk_update_creates_real_attendance_days_and_increments_version(): void
    {
        $user = User::factory()->create();
        $draft = $this->createDraft($user);

        $response = $this->actingAs($user)->putJson("/api/attendance/monthly-drafts/{$draft['id']}/days", [
            'expected_version' => 1,
            'days' => [$this->cleanDayPayload('2026-07-01'), $this->cleanDayPayload('2026-07-02')],
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('status', 'ACCEPTED');
        $this->assertSame(2, $response->json('draft.version'));

        $day = AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', '2026-07-01')->firstOrFail();
        $this->assertSame('remote', $day->work_location_type);
        $this->assertSame(1, $day->breaks()->count());
    }

    public function test_a_stale_expected_version_is_rejected_with_409(): void
    {
        $user = User::factory()->create();
        $draft = $this->createDraft($user);

        $this->actingAs($user)->putJson("/api/attendance/monthly-drafts/{$draft['id']}/days", [
            'expected_version' => 1,
            'days' => [$this->cleanDayPayload('2026-07-01')],
        ])->assertSuccessful();

        // 古いバージョン(1)のまま再度送ると競合になる。
        $this->actingAs($user)->putJson("/api/attendance/monthly-drafts/{$draft['id']}/days", [
            'expected_version' => 1,
            'days' => [$this->cleanDayPayload('2026-07-02')],
        ])->assertStatus(409);
    }

    public function test_retrying_with_the_same_idempotency_key_does_not_reprocess(): void
    {
        $user = User::factory()->create();
        $draft = $this->createDraft($user);

        $payload = [
            'expected_version' => 1,
            'days' => [$this->cleanDayPayload('2026-07-01')],
        ];

        $first = $this->actingAs($user)->putJson(
            "/api/attendance/monthly-drafts/{$draft['id']}/days", $payload, ['Idempotency-Key' => 'retry-key-1']
        );
        $first->assertSuccessful();

        $second = $this->actingAs($user)->putJson(
            "/api/attendance/monthly-drafts/{$draft['id']}/days", $payload, ['Idempotency-Key' => 'retry-key-1']
        );
        $second->assertSuccessful();

        // 2回目は再処理されず、下書きのversionは1回分しか進んでいない。
        $this->assertSame(2, $second->json('draft.version'));
    }

    public function test_a_day_with_invalid_time_range_is_rejected_but_others_still_accepted(): void
    {
        $user = User::factory()->create();
        $draft = $this->createDraft($user);

        $invalidDay = $this->cleanDayPayload('2026-07-03');
        $invalidDay['startTime'] = '18:00';
        $invalidDay['endTime'] = '09:00';

        $response = $this->actingAs($user)->putJson("/api/attendance/monthly-drafts/{$draft['id']}/days", [
            'expected_version' => 1,
            'days' => [$this->cleanDayPayload('2026-07-01'), $invalidDay],
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('status', 'PARTIALLY_ACCEPTED');
        $statuses = collect($response->json('results'))->pluck('status', 'date');
        $this->assertSame('ACCEPTED', $statuses['2026-07-01']);
        $this->assertSame('REJECTED', $statuses['2026-07-03']);
    }

    public function test_a_draft_with_only_confirmed_values_can_be_validated_and_submitted(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        $draft = $this->createDraft($user);

        $this->actingAs($user)->putJson("/api/attendance/monthly-drafts/{$draft['id']}/days", [
            'expected_version' => 1,
            'days' => [$this->cleanDayPayload('2026-07-01')],
        ])->assertSuccessful();

        $validate = $this->actingAs($user)->postJson("/api/attendance/monthly-drafts/{$draft['id']}/validate");
        $validate->assertSuccessful();
        $this->assertSame(MonthlyDraftStatus::READY_TO_SUBMIT, $validate->json('draft.status'));

        $submit = $this->actingAs($user)->postJson("/api/attendance/monthly-drafts/{$draft['id']}/submit", [
            'approver_user_id' => $approver->id,
        ]);
        $submit->assertSuccessful();
        $this->assertSame(MonthlyDraftStatus::SUBMITTED, $submit->json('status'));

        $month = AttendanceMonth::query()->where('user_id', $user->id)->where('year_month', '2026-07')->first();
        $this->assertNotNull($month);
        $this->assertSame('submitted', $month->status);
    }

    public function test_unconfirmed_ai_inferred_values_block_submission(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        $draft = $this->createDraft($user);

        $aiDay = $this->cleanDayPayload('2026-07-01');
        $aiDay['source'] = FieldSourceType::AI_INFERRED;

        $this->actingAs($user)->putJson("/api/attendance/monthly-drafts/{$draft['id']}/days", [
            'expected_version' => 1,
            'days' => [$aiDay],
        ])->assertSuccessful();

        $validate = $this->actingAs($user)->postJson("/api/attendance/monthly-drafts/{$draft['id']}/validate");
        $this->assertSame(MonthlyDraftStatus::NEEDS_REVIEW, $validate->json('draft.status'));
        $this->assertNotEmpty($validate->json('unconfirmed_fields'));

        $this->actingAs($user)->postJson("/api/attendance/monthly-drafts/{$draft['id']}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);

        // ユーザーがAI推定値を確認すると申請できるようになる。
        $provenances = FieldProvenance::query()
            ->where('entity_type', FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT)
            ->where('entity_id', $draft['id'])
            ->get();

        foreach ($provenances as $provenance) {
            $this->actingAs($user)->postJson(
                "/api/attendance/monthly-drafts/{$draft['id']}/fields/{$provenance->id}/confirm"
            )->assertSuccessful();
        }

        $revalidate = $this->actingAs($user)->postJson("/api/attendance/monthly-drafts/{$draft['id']}/validate");
        $this->assertSame(MonthlyDraftStatus::READY_TO_SUBMIT, $revalidate->json('draft.status'));

        $this->actingAs($user)->postJson("/api/attendance/monthly-drafts/{$draft['id']}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
    }

    public function test_an_employee_cannot_view_another_employees_draft(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = $this->createDraft($owner);

        $this->actingAs($other)->getJson("/api/attendance/monthly-drafts/{$draft['id']}")->assertForbidden();
    }

    public function test_it_lists_only_the_current_users_drafts_newest_first(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->createDraft($user, '2026-06');
        $this->createDraft($user, '2026-07');
        $this->createDraft($other, '2026-07');

        $response = $this->actingAs($user)->getJson('/api/attendance/monthly-drafts/mine');

        $response->assertSuccessful();
        $months = collect($response->json())->pluck('target_month');
        $this->assertSame(['2026-07', '2026-06'], $months->all());
    }

    public function test_fields_endpoint_returns_the_latest_provenance_per_field(): void
    {
        $user = User::factory()->create();
        $draft = $this->createDraft($user);

        $aiDay = $this->cleanDayPayload('2026-07-01');
        $aiDay['source'] = FieldSourceType::AI_INFERRED;

        $this->actingAs($user)->putJson("/api/attendance/monthly-drafts/{$draft['id']}/days", [
            'expected_version' => 1,
            'days' => [$aiDay],
        ])->assertSuccessful();

        $response = $this->actingAs($user)->getJson("/api/attendance/monthly-drafts/{$draft['id']}/fields");

        $response->assertSuccessful();
        $fieldNames = collect($response->json())->pluck('field_name');
        $this->assertTrue($fieldNames->contains('2026-07-01:start_time'));
        $sourceTypes = collect($response->json())->pluck('source_type')->unique();
        $this->assertSame([FieldSourceType::AI_INFERRED], $sourceTypes->all());
    }

    public function test_an_employee_cannot_view_another_employees_draft_fields(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = $this->createDraft($owner);

        $this->actingAs($other)->getJson("/api/attendance/monthly-drafts/{$draft['id']}/fields")->assertForbidden();
    }
}
