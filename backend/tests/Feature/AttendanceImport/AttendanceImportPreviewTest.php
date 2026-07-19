<?php

namespace Tests\Feature\AttendanceImport;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/26-usecases-monthly-import.md「データの保持場所」: 下書き・インポートセッションの保持は
 * mcp/自身のDBで行うため、backend/はAttendanceDifferenceDetectorを再利用したステートレスな
 * 検証エンドポイントのみを提供する(何も保存しない)。
 */
class AttendanceImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flags_a_proposed_day_with_no_existing_attendance_or_punches(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/attendance/import-preview', [
            'target_month' => '2026-07',
            'days' => [
                ['date' => '2026-07-01', 'startTime' => '09:00', 'endTime' => '18:00', 'breaks' => []],
            ],
        ]);

        $response->assertOk();
        $this->assertNull($response->json('items.0.existing'));
        $this->assertContains(
            'MISSING_EXISTING_ATTENDANCE',
            collect($response->json('items.0.differences'))->pluck('code')->all(),
        );
        $this->assertSame([], $response->json('missing_dates'));
    }

    public function test_it_flags_a_time_difference_against_an_existing_attendance_day(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-07-01',
            'actual_start_at' => '2026-07-01T09:00:00+09:00',
            'actual_end_at' => '2026-07-01T18:00:00+09:00',
            'reason' => 'テスト登録',
        ])->assertSuccessful();

        $response = $this->actingAs($employee)->postJson('/api/attendance/import-preview', [
            'target_month' => '2026-07',
            'days' => [
                ['date' => '2026-07-01', 'startTime' => '09:30', 'endTime' => '18:00', 'breaks' => []],
            ],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('items.0.existing.id'));
        $this->assertContains('START_TIME_DIFF', collect($response->json('items.0.differences'))->pluck('code')->all());
    }

    public function test_it_reports_dates_missing_from_the_report(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-07-02',
            'actual_start_at' => '2026-07-02T09:00:00+09:00',
            'actual_end_at' => '2026-07-02T18:00:00+09:00',
            'reason' => 'テスト登録',
        ])->assertSuccessful();

        $response = $this->actingAs($employee)->postJson('/api/attendance/import-preview', [
            'target_month' => '2026-07',
            'days' => [
                ['date' => '2026-07-01', 'startTime' => '09:00', 'endTime' => '18:00', 'breaks' => []],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(['2026-07-02'], $response->json('missing_dates'));
    }
}
