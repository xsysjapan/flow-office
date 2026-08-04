<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\Role;
use App\Models\StoredEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    public function test_admin_can_export_attendance_as_excel_with_detail_sheet_for_a_single_employee(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => '締め済み社員']);
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
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

        $day = AttendanceDay::query()->create([
            'user_id' => $employee->id,
            'work_date' => '2026-06-15',
            'status' => 'clocked_out',
            'actual_start_at' => '2026-06-15 09:00:00',
            'actual_end_at' => '2026-06-15 18:00:00',
            'work_type' => 'normal',
            'day_classification' => 'working_day',
        ]);
        $day->calculation()->create([
            'work_minutes' => 480,
            'prescribed_work_minutes' => 480,
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 0,
            'late_night_work_minutes' => 0,
            'legal_holiday_work_minutes' => 0,
            'prescribed_holiday_work_minutes' => 0,
        ]);

        $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month=2026-06');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmpFile, $response->getContent());

        $spreadsheet = IOFactory::load($tmpFile);
        $this->assertSame(['月次サマリ', '日別明細'], $spreadsheet->getSheetNames());
        $this->assertSame('社員名', $spreadsheet->getSheet(0)->getCell('B1')->getValue());
        $this->assertSame('締め済み社員', $spreadsheet->getSheet(0)->getCell('B2')->getValue());

        unlink($tmpFile);

        $this->assertDatabaseHas('legacy_stored_events', [
            'aggregate_type' => 'export',
            'event_type' => 'export.created',
        ]);
        $event = StoredEvent::query()->where('aggregate_type', 'export')->latest('id')->first();
        $this->assertSame('attendance_xlsx', $event->payload['export_type']);
    }

    public function test_admin_export_excel_omits_detail_sheet_for_multiple_employees(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        foreach (['社員A', '社員B'] as $name) {
            $employee = User::factory()->create(['name' => $name]);
            AttendanceMonth::query()->create([
                'user_id' => $employee->id,
                'year_month' => '2026-06',
                'status' => 'approved',
            ]);
        }

        $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month=2026-06');

        $response->assertSuccessful();

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmpFile, $response->getContent());

        $spreadsheet = IOFactory::load($tmpFile);
        $this->assertSame(['月次サマリ'], $spreadsheet->getSheetNames());

        unlink($tmpFile);
    }

    public function test_non_admin_cannot_export_attendance_excel(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/api/exports/attendance.xlsx?year_month=2026-06')->assertForbidden();
    }
}
