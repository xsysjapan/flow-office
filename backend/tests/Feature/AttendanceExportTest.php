<?php

namespace Tests\Feature;

use App\Models\AttendanceMonth;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-E001: 勤怠CSVを出力する。締め後(UC-A011)の月次勤怠のみが対象。
 */
class AttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_a_csv_of_closed_months_only(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $closedEmployee = User::factory()->create(['name' => '締め済み社員']);
        AttendanceMonth::query()->create([
            'user_id' => $closedEmployee->id,
            'year_month' => '2026-06',
            'status' => 'closed',
            'snapshot_json' => [
                'actual_work_minutes' => 9600,
                'prescribed_work_minutes' => 9600,
                'non_statutory_overtime_minutes' => 0,
                'statutory_overtime_minutes' => 120,
                'late_night_minutes' => 60,
                'legal_holiday_work_minutes' => 0,
                'company_holiday_work_minutes' => 0,
            ],
        ]);

        $notClosedEmployee = User::factory()->create(['name' => '未締め社員']);
        AttendanceMonth::query()->create([
            'user_id' => $notClosedEmployee->id,
            'year_month' => '2026-06',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->get('/api/exports/attendance?year_month=2026-06');

        $response->assertSuccessful();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('締め済み社員', $csv);
        $this->assertStringNotContainsString('未締め社員', $csv);
        $this->assertStringContainsString('120', $csv);
    }

    public function test_non_admin_cannot_export_attendance_csv(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/api/exports/attendance?year_month=2026-06')->assertForbidden();
    }
}
