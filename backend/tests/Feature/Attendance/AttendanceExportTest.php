<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceMonth;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-E001: 勤怠CSVを出力する。承認済み・締め後(UC-A011)の月次勤怠が対象
 * (バックオフィス確認・締め前でもCSV/帳票を出力できる必要があるため、締め済みのみに
 * 限定しない。docs/14-usecases-export.md参照)。
 */
class AttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_a_csv_of_approved_or_closed_months(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $closedEmployee = User::factory()->create(['name' => '締め済み社員']);
        AttendanceMonth::query()->create([
            'user_id' => $closedEmployee->id,
            'year_month' => '2026-06',
            'status' => 'closed',
            'snapshot_json' => [
                'work_minutes' => 9600,
                'prescribed_work_minutes' => 9600,
                'statutory_within_overtime_minutes' => 0,
                'statutory_excess_overtime_minutes' => 120,
                'late_night_work_minutes' => 60,
                'legal_holiday_work_minutes' => 0,
                'prescribed_holiday_work_minutes' => 0,
            ],
        ]);

        $approvedEmployee = User::factory()->create(['name' => '承認済み未締め社員']);
        AttendanceMonth::query()->create([
            'user_id' => $approvedEmployee->id,
            'year_month' => '2026-06',
            'status' => 'approved',
        ]);

        $submittedEmployee = User::factory()->create(['name' => '提出済み未承認社員']);
        AttendanceMonth::query()->create([
            'user_id' => $submittedEmployee->id,
            'year_month' => '2026-06',
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($admin)->get('/api/exports/attendance?year_month=2026-06');

        $response->assertSuccessful();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('締め済み社員', $csv);
        $this->assertStringContainsString('承認済み未締め社員', $csv);
        $this->assertStringNotContainsString('提出済み未承認社員', $csv);
        $this->assertStringContainsString('120', $csv);
    }

    public function test_non_admin_cannot_export_attendance_csv(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/api/exports/attendance?year_month=2026-06')->assertForbidden();
    }
}
