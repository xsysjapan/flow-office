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

    public function test_admin_can_export_a_csv_across_multiple_months_and_employees(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employeeA = User::factory()->create(['name' => '社員A']);
        $employeeB = User::factory()->create(['name' => '社員B']);
        $employeeC = User::factory()->create(['name' => '対象外社員']);

        foreach ([$employeeA, $employeeB, $employeeC] as $employee) {
            foreach (['2026-06', '2026-07'] as $yearMonth) {
                AttendanceMonth::query()->create([
                    'user_id' => $employee->id,
                    'year_month' => $yearMonth,
                    'status' => 'approved',
                ]);
            }
        }

        $response = $this->actingAs($admin)->get(
            '/api/exports/attendance?year_month[]=2026-06&year_month[]=2026-07&user_id[]='.$employeeA->id.'&user_id[]='.$employeeB->id
        );

        $response->assertSuccessful();
        $this->assertStringContainsString('attendance_2026-06_2026-07.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        // ヘッダー1行 + (2社員 × 2ヶ月)の4行。対象外社員は含まれない。
        $this->assertCount(5, $lines);
        $this->assertStringContainsString('社員A', $csv);
        $this->assertStringContainsString('社員B', $csv);
        $this->assertStringNotContainsString('対象外社員', $csv);
        $this->assertStringContainsString('2026-06', $csv);
        $this->assertStringContainsString('2026-07', $csv);
    }

    public function test_admin_export_excel_zips_a_single_employee_across_multiple_months(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => '複数月社員']);
        foreach (['2026-06', '2026-07'] as $yearMonth) {
            AttendanceMonth::query()->create([
                'user_id' => $employee->id,
                'year_month' => $yearMonth,
                'status' => 'approved',
            ]);
        }

        $response = $this->actingAs($admin)->get(
            '/api/exports/attendance.xlsx?year_month[]=2026-06&year_month[]=2026-07&user_id[]='.$employee->id
        );

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('attendance_2026-06_2026-07.zip', $response->headers->get('Content-Disposition'));

        $tmpZip = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmpZip, $response->getContent());

        $zip = new \ZipArchive;
        $zip->open($tmpZip);
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName($employee->id.'_2026-06.xlsx'));
        $this->assertNotFalse($zip->locateName($employee->id.'_2026-07.xlsx'));
        $zip->close();
        unlink($tmpZip);
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

    public function test_admin_export_excel_returns_zip_of_per_employee_workbooks_for_multiple_employees(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employeeIds = [];
        foreach (['社員A', '社員B'] as $name) {
            $employee = User::factory()->create(['name' => $name]);
            $employeeIds[] = $employee->id;
            AttendanceMonth::query()->create([
                'user_id' => $employee->id,
                'year_month' => '2026-06',
                'status' => 'approved',
            ]);
        }

        $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month=2026-06');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('attendance_2026-06.zip', $response->headers->get('Content-Disposition'));

        $tmpZip = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmpZip, $response->getContent());

        $zip = new \ZipArchive;
        $zip->open($tmpZip);
        $this->assertSame(2, $zip->numFiles);

        foreach ($employeeIds as $userId) {
            $entryName = $userId.'_2026-06.xlsx';
            $this->assertNotFalse($zip->locateName($entryName), "expected {$entryName} in zip");

            $xlsxContents = $zip->getFromName($entryName);
            $tmpXlsx = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($tmpXlsx, $xlsxContents);

            $spreadsheet = IOFactory::load($tmpXlsx);
            $this->assertSame(['月次サマリ', '日別明細'], $spreadsheet->getSheetNames());

            unlink($tmpXlsx);
        }

        $zip->close();
        unlink($tmpZip);

        $event = StoredEvent::query()->where('aggregate_type', 'export')->latest('id')->first();
        $this->assertSame('attendance_xlsx_zip', $event->payload['export_type']);
    }

    public function test_non_admin_cannot_export_attendance_excel(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/api/exports/attendance.xlsx?year_month=2026-06')->assertForbidden();
    }

    public function test_generic_tsv_format_uses_tab_delimiter_japanese_headers_and_colon_time(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => 'TSV社員']);
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'approved',
            'snapshot_json' => [
                'work_minutes' => 480,
                'prescribed_work_minutes' => 480,
                'statutory_within_overtime_minutes' => 0,
                'statutory_excess_overtime_minutes' => 120,
                'late_night_work_minutes' => 0,
                'legal_holiday_work_minutes' => 0,
                'prescribed_holiday_work_minutes' => 0,
            ],
        ]);

        $response = $this->actingAs($admin)->get('/api/exports/attendance?year_month=2026-06&format=generic_tsv');

        $response->assertSuccessful();
        $this->assertStringContainsString('attendance_2026-06_generic_tsv.tsv', $response->headers->get('Content-Disposition'));

        $tsv = $response->streamedContent();
        $lines = explode("\n", trim($tsv));

        $this->assertSame(
            "社員番号\t氏名\t対象年月\t実労働時間\t所定労働時間\t法定内残業\t法定外残業\t深夜労働時間\t法定休日労働\t所定休日労働",
            $lines[0]
        );
        $this->assertStringContainsString('TSV社員', $lines[1]);
        $this->assertStringContainsString('8:00', $lines[1]);
        $this->assertStringContainsString('2:00', $lines[1]);
    }

    public function test_generic_sjis_format_uses_shift_jis_encoding(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => 'SJIS社員']);
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'approved',
            'snapshot_json' => [
                'work_minutes' => 480,
                'prescribed_work_minutes' => 480,
                'statutory_within_overtime_minutes' => 0,
                'statutory_excess_overtime_minutes' => 120,
                'late_night_work_minutes' => 0,
                'legal_holiday_work_minutes' => 0,
                'prescribed_holiday_work_minutes' => 0,
            ],
        ]);

        $response = $this->actingAs($admin)->get('/api/exports/attendance?year_month=2026-06&format=generic_sjis');

        $response->assertSuccessful();
        $this->assertStringContainsString('attendance_2026-06_generic_sjis.csv', $response->headers->get('Content-Disposition'));

        $raw = $response->streamedContent();
        $utf8 = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');

        $this->assertStringContainsString('SJIS社員', $utf8);
        $this->assertStringContainsString('480', $utf8);
        $this->assertStringContainsString('120', $utf8);
    }

    public function test_moneyforward_format_columns(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => 'MF社員']);
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'approved',
            'snapshot_json' => [
                'work_days_weekday' => 20,
                'work_days_prescribed_holiday' => 1,
                'work_days_legal_holiday' => 0,
                'absence_days' => 1,
                'weekday_regular_work_minutes' => 9600,
                'weekday_statutory_within_overtime_minutes' => 0,
                'weekday_statutory_excess_overtime_minutes' => 30,
                'weekday_late_night_prescribed_work_minutes' => 0,
                'weekday_late_night_statutory_within_overtime_minutes' => 0,
                'weekday_late_night_statutory_excess_overtime_minutes' => 30,
                'prescribed_holiday_work_minutes' => 480,
                'prescribed_holiday_late_night_prescribed_work_minutes' => 0,
                'prescribed_holiday_statutory_within_overtime_minutes' => 0,
                'prescribed_holiday_statutory_excess_overtime_minutes' => 60,
                'prescribed_holiday_late_night_statutory_excess_overtime_minutes' => 0,
                'legal_holiday_work_minutes' => 0,
                'late_night_legal_holiday_work_minutes' => 0,
                'paid_leave_days' => 1.0,
                'paid_leave_minutes' => 0,
            ],
        ]);

        $response = $this->actingAs($admin)->get('/api/exports/attendance?year_month=2026-06&format=moneyforward');

        $response->assertSuccessful();
        $this->assertStringContainsString('attendance_2026-06_moneyforward.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $lines = explode("\n", trim($csv));

        $this->assertSame(
            'Version,従業員番号,氏名,'
            .'出勤日数（平日）,出勤日数（所定休日）,出勤日数（法定休日）,欠勤日数（平日）,'
            .'遅刻回数（平日）,早退回数（平日）,'
            .'所定時間（平日）,休憩時間（平日）,深夜所定時間（平日）,深夜休憩時間（平日）,'
            .'所定外時間（平日）,法定外時間（平日）,深夜所定外時間（平日）,深夜法定外時間（平日）,'
            .'所定外休憩時間（平日）,深夜所定外休憩時間（平日）,法定外休憩時間（平日）,深夜法定外休憩時間（平日）,'
            .'遅刻時間（平日）,早退時間（平日）,'
            .'所定時間（所定休日）,深夜所定時間（所定休日）,所定外時間（所定休日）,法定外時間（所定休日）,深夜法定外時間（所定休日）,'
            .'所定時間（法定休日）,深夜所定時間（法定休日）,所定外時間（法定休日）,法定外時間（法定休日）,深夜法定外時間（法定休日）,'
            .'有休取得日数,有休取得時間数,代休取得日数,代休取得時間数',
            $lines[0]
        );
        $this->assertSame(
            '3,'.$employee->id.',MF社員,'
            .'20,1,0,1,'
            .'0,0,'
            .'160,0,0,0,'
            .'0,0.5,0,0.5,'
            .'0,0,0,0,'
            .'0,0,'
            .'8,0,0,1,0,'
            .'0,0,0,0,0,'
            .'1,0,0,0',
            $lines[1]
        );
    }

    public function test_freee_format_columns(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => 'freee社員']);
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'approved',
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

        $response = $this->actingAs($admin)->get('/api/exports/attendance?year_month=2026-06&format=freee');

        $response->assertSuccessful();
        $this->assertStringContainsString('attendance_2026-06_freee.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $lines = explode("\n", trim($csv));

        $this->assertSame(
            '従業員番号,集計開始日,集計終了日,所定労働時間,法定内残業時間,時間外労働時間,深夜労働時間,法定休日労働時間,総労働時間',
            $lines[0]
        );
        $this->assertStringContainsString($employee->id.',2026/06/01,2026/06/30,9600,0,120,60,0,9600', $lines[1]);
    }

    public function test_unknown_format_returns_validation_error(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)
            ->get('/api/exports/attendance?year_month=2026-06&format=yayoi')
            ->assertStatus(422);
    }
}
