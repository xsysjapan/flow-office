<?php

namespace Tests\Feature\AttendanceImport;

use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\FieldProvenance;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-R001: 作業報告書インポートセッション(docs/26-usecases-monthly-import.md)。
 */
class AttendanceImportSessionTest extends TestCase
{
    use RefreshDatabase;

    private function createSession(User $user, string $targetMonth = '2026-07'): array
    {
        return $this->actingAs($user)->postJson('/api/attendance/import-sessions', [
            'target_month' => $targetMonth,
            'source_file_name' => '2026-07-work-report.xlsx',
            'source_file_hash' => 'sha256:dummy',
        ])->assertCreated()->json();
    }

    public function test_full_flow_creates_session_uploads_data_previews_and_applies_to_a_new_draft(): void
    {
        $user = User::factory()->create();
        $session = $this->createSession($user);

        $upload = $this->actingAs($user)->postJson("/api/attendance/import-sessions/{$session['id']}/data", [
            'days' => [
                ['date' => '2026-07-01', 'startTime' => '09:00', 'endTime' => '18:00', 'breaks' => [['startTime' => '12:00', 'endTime' => '13:00']], 'workLocation' => 'REMOTE', 'confidence' => 'high'],
                ['date' => '2026-07-02', 'startTime' => '09:00', 'endTime' => '18:00', 'confidence' => 'high'],
            ],
        ]);
        $upload->assertSuccessful();

        $preview = $this->actingAs($user)->postJson("/api/attendance/import-sessions/{$session['id']}/preview");
        $preview->assertSuccessful();
        $this->assertCount(2, $preview->json('items'));
        foreach ($preview->json('items') as $item) {
            // 既存勤怠が無い日にいきなり出勤実績があるので警告が出るが、ブロッキングではない。
            $this->assertFalse($item['has_blocking_differences']);
        }

        $apply = $this->actingAs($user)->postJson("/api/attendance/import-sessions/{$session['id']}/apply");
        $apply->assertSuccessful();
        $this->assertSame('applied', $apply->json('session.status'));

        $draftId = $apply->json('draft.id');
        $this->assertNotNull($draftId);

        $day = AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', '2026-07-01')->first();
        $this->assertNotNull($day);
        $this->assertSame('09:00', $day->actual_start_at->format('H:i'));

        // 差異のない日は自動的に確認済み扱いになる(docs/26「不明点の確認」)。
        $provenance = FieldProvenance::query()
            ->where('entity_type', FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT)
            ->where('entity_id', $draftId)
            ->where('field_name', '2026-07-01:start_time')
            ->firstOrFail();
        $this->assertTrue($provenance->isConfirmed());
    }

    public function test_preview_detects_a_leave_conflict(): void
    {
        $user = User::factory()->create();

        $leaveDay = AttendanceDay::query()->create([
            'user_id' => $user->id,
            'work_date' => '2026-07-05',
            'status' => 'not_started',
            'source' => AttendanceDaySource::MANUAL,
            'utc_offset_minutes' => 540,
            'work_type' => 'paid_leave_full',
        ]);
        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $user->id,
            'granted_on' => '2026-01-01',
            'expires_on' => '2027-01-01',
            'granted_days' => 10,
            'used_days' => 0,
            'remaining_days' => 10,
            'grant_reason' => 'test',
        ]);
        $leaveRequest = PaidLeaveRequest::query()->create([
            'user_id' => $user->id,
            'approver_user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'full',
            'target_date' => '2026-07-05',
            'requested_days' => 1,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);
        PaidLeaveUsage::query()->create([
            'user_id' => $user->id,
            'attendance_day_id' => $leaveDay->id,
            'paid_leave_grant_id' => $grant->id,
            'paid_leave_request_id' => $leaveRequest->id,
            'used_on' => '2026-07-05',
            'used_days' => 1,
            'used_minutes' => 0,
            'usage_type' => 'full',
        ]);

        $session = $this->createSession($user);
        $this->actingAs($user)->postJson("/api/attendance/import-sessions/{$session['id']}/data", [
            'days' => [['date' => '2026-07-05', 'startTime' => '09:00', 'endTime' => '18:00', 'confidence' => 'medium']],
        ])->assertSuccessful();

        $preview = $this->actingAs($user)->postJson("/api/attendance/import-sessions/{$session['id']}/preview");
        $preview->assertSuccessful();

        $codes = collect($preview->json('items.0.differences'))->pluck('code');
        $this->assertContains('LEAVE_CONFLICT', $codes);
        $this->assertTrue($preview->json('items.0.has_blocking_differences') === false);
    }

    public function test_preview_flags_existing_attendance_missing_from_the_report(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/attendance/days', [
            'user_id' => $user->id,
            'work_date' => '2026-07-10',
            'actual_start_at' => '2026-07-10T09:00:00+09:00',
            'actual_end_at' => '2026-07-10T18:00:00+09:00',
            'breaks' => [],
            'reason' => '既存実績',
        ])->assertCreated();

        $session = $this->createSession($user);
        $this->actingAs($user)->postJson("/api/attendance/import-sessions/{$session['id']}/data", [
            'days' => [['date' => '2026-07-01', 'startTime' => '09:00', 'endTime' => '18:00', 'confidence' => 'high']],
        ])->assertSuccessful();

        $preview = $this->actingAs($user)->postJson("/api/attendance/import-sessions/{$session['id']}/preview");
        $preview->assertSuccessful();

        $missingItem = collect($preview->json('items'))->firstWhere('work_date', '2026-07-10');
        $this->assertNotNull($missingItem);
        $this->assertSame('MISSING_IN_REPORT', $missingItem['differences'][0]['code']);
    }

    public function test_an_employee_cannot_access_another_employees_import_session(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $session = $this->createSession($owner);

        $this->actingAs($other)->getJson("/api/attendance/import-sessions/{$session['id']}")->assertForbidden();
    }
}
