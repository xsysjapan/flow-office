<?php

namespace Tests\Feature\Attendance;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Export\Services\AttendanceExcelBuilder;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\RevokePaidLeaveGrant;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveUsage;
use App\Models\PaidLeaveUsageAllocation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * UC-E001: 勤怠CSVを出力する。承認済み・締め後(UC-A011)の月次勤怠が対象
 * (バックオフィス確認・締め前でもCSV/帳票を出力できる必要があるため、締め済みのみに
 * 限定しない。docs/14-usecases-export.md参照)。
 */
class AttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_note_contains_the_holiday_name_without_an_attendance_day(): void
    {
        $employee = User::factory()->create();
        $calendar = CompanyCalendar::query()->create(['name' => 'Head office', 'week_starts_on' => 1, 'is_default' => true]);
        $year = $calendar->years()->create([
            'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published',
        ]);
        $year->days()->create([
            'date' => '2026-08-11', 'day_type' => 'company_holiday', 'is_working_day' => false,
            'is_legal_holiday' => false, 'is_company_holiday' => true, 'is_public_holiday' => true,
            'public_holiday_name' => '山の日', 'schedule_state' => 'OFF',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => 'Standard', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);
        $month = AttendanceMonth::query()->create([
            'user_id' => $employee->id, 'year_month' => '2026-08', 'status' => 'approved',
        ]);

        $spreadsheet = app(AttendanceExcelBuilder::class)->buildForMonth($month, '2026-08');

        $this->assertSame('山の日', $spreadsheet->getActiveSheet()->getCell('K23')->getValue());
    }

    public function test_excel_includes_holiday_work_performed_on_the_last_day_of_a_31_day_month(): void
    {
        $employee = User::factory()->create();
        $month = AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-07',
            'status' => 'closed',
            'snapshot_json' => [
                'work_minutes' => 480,
                'prescribed_work_minutes' => 0,
                'weekday_regular_work_minutes' => 0,
                'statutory_within_overtime_minutes' => 0,
                'statutory_excess_overtime_minutes' => 0,
                'prescribed_holiday_work_minutes' => 480,
                'legal_holiday_work_minutes' => 0,
            ],
        ]);
        $day = AttendanceDay::query()->create([
            'user_id' => $employee->id,
            'work_date' => '2026-07-31',
            'status' => 'clocked_out',
            'actual_start_at' => '2026-07-31 09:00:00',
            'actual_end_at' => '2026-07-31 18:00:00',
            'day_classification' => 'prescribed_holiday',
        ]);
        $day->calculation()->create([
            'work_minutes' => 480,
            'prescribed_work_minutes' => 0,
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 0,
            'legal_holiday_work_minutes' => 0,
            'prescribed_holiday_work_minutes' => 480,
        ]);

        $sheet = app(AttendanceExcelBuilder::class)
            ->buildForMonth($month, '2026-07')
            ->getActiveSheet();

        // Header is row 12, therefore July 31 is row 43.
        $this->assertSame('09:00', $sheet->getCell('C43')->getValue());
        $this->assertSame('18:00', $sheet->getCell('D43')->getValue());
        $this->assertContains($sheet->getCell('E43')->getValue(), [null, 0, 0.0]);
        $this->assertEqualsWithDelta(480 / 1440, $sheet->getCell('F43')->getValue(), 0.000001);
    }

    public function test_excel_does_not_double_count_prescribed_holiday_work_allocated_as_weekly_overtime(): void
    {
        $employee = User::factory()->create();
        $month = AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-07',
            'status' => 'closed',
            'snapshot_json' => [
                'work_minutes' => 480,
                'prescribed_statutory_within_work_minutes' => 0,
                'non_prescribed_statutory_excess_work_minutes' => 480,
                'statutory_excess_overtime_minutes' => 480,
                'prescribed_holiday_work_minutes' => 480,
                'legal_holiday_work_minutes' => 0,
            ],
        ]);
        $day = AttendanceDay::query()->create([
            'user_id' => $employee->id,
            'work_date' => '2026-07-18',
            'status' => 'clocked_out',
            'actual_start_at' => '2026-07-18 09:00:00',
            'actual_end_at' => '2026-07-18 18:00:00',
            'day_classification' => 'prescribed_holiday',
        ]);
        $day->calculation()->create([
            'work_minutes' => 480,
            'prescribed_work_minutes' => 0,
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 480,
            'prescribed_statutory_within_work_minutes' => 0,
            'non_prescribed_statutory_within_work_minutes' => 0,
            'prescribed_statutory_excess_work_minutes' => 0,
            'non_prescribed_statutory_excess_work_minutes' => 480,
            'legal_holiday_work_minutes' => 0,
            'prescribed_holiday_work_minutes' => 480,
        ]);

        $sheet = app(AttendanceExcelBuilder::class)
            ->buildForMonth($month, '2026-07')
            ->getActiveSheet();

        // Header is row 12, therefore July 18 is row 30.
        $this->assertContains($sheet->getCell('E30')->getValue(), [null, 0, 0.0]);
        $this->assertEqualsWithDelta(480 / 1440, $sheet->getCell('F30')->getValue(), 0.000001);
        $this->assertContains($sheet->getCell('E44')->getValue(), [null, 0, 0.0]);
        $this->assertEqualsWithDelta(480 / 1440, $sheet->getCell('F44')->getValue(), 0.000001);
    }

    public function test_admin_can_export_a_csv_of_approved_or_closed_months(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

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

    public function test_csv_with_include_submitted_outputs_submitted_month_but_not_returned_or_not_submitted(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $submittedEmployee = User::factory()->create(['name' => '提出済みCSV社員']);
        AttendanceMonth::query()->create([
            'user_id' => $submittedEmployee->id,
            'year_month' => '2026-06',
            'status' => AttendanceMonthStatus::SUBMITTED,
            'snapshot_json' => $this->submittedSnapshot(),
        ]);

        // 差戻し中の月はスナップショットを持つが対象外。
        $returnedEmployee = User::factory()->create(['name' => '差戻しCSV社員']);
        AttendanceMonth::query()->create([
            'user_id' => $returnedEmployee->id,
            'year_month' => '2026-06',
            'status' => AttendanceMonthStatus::RETURNED,
            'snapshot_json' => $this->submittedSnapshot(),
        ]);

        // 未提出の月は対象外。
        $notSubmittedEmployee = User::factory()->create(['name' => '未提出CSV社員']);
        AttendanceMonth::query()->create([
            'user_id' => $notSubmittedEmployee->id,
            'year_month' => '2026-06',
            'status' => AttendanceMonthStatus::NOT_SUBMITTED,
            'snapshot_json' => null,
        ]);

        // Excelと同じく "1" と "true" のどちらでも提出済みを含める。
        foreach (['1', 'true'] as $value) {
            $response = $this->actingAs($admin)->get('/api/exports/attendance?year_month=2026-06&include_submitted='.$value);

            $response->assertSuccessful();
            $csv = $response->streamedContent();
            $this->assertStringContainsString('提出済みCSV社員', $csv);
            $this->assertStringNotContainsString('差戻しCSV社員', $csv);
            $this->assertStringNotContainsString('未提出CSV社員', $csv);
        }
    }

    public function test_admin_can_export_a_csv_across_multiple_months_and_employees(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

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
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

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
        $this->assertStringContainsString(
            rawurlencode('勤怠管理表_複数月社員_2026年06月-2026年07月.zip'),
            $response->headers->get('Content-Disposition'),
        );

        $tmpZip = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmpZip, $response->getContent());

        $zip = new \ZipArchive;
        $zip->open($tmpZip);
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('勤怠管理表_複数月社員_2026年06月.xlsx'));
        $this->assertNotFalse($zip->locateName('勤怠管理表_複数月社員_2026年07月.xlsx'));
        $zip->close();
        unlink($tmpZip);
    }

    public function test_non_admin_cannot_export_attendance_csv(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/api/exports/attendance?year_month=2026-06')->assertForbidden();
    }

    public function test_admin_can_export_attendance_as_single_sheet_management_report_for_a_single_employee(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => '締め 済み　社員']);

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

        // 有給は付与・申請・承認のイベント経由で作る(口座集約が付与と消化を知る状態。原則2)。
        // 6/15は勤怠日の実績を持つ日のため、消化はそれ以外の日に申請する(6月の消化は計1.5日)。
        // 7月の付与は7月の消化の後に作る(付与日の昇順。6月末時点の残数には影響しない)。
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));
        $this->approvePaidLeave($employee, $admin, '2026-06-22', 'full');
        $this->approvePaidLeave($employee, $admin, '2026-06-23', 'am_half');
        $this->approvePaidLeave($employee, $admin, '2026-07-15', 'full');
        $this->approvePaidLeave($employee, $admin, '2026-07-16', 'full');
        $this->approvePaidLeave($employee, $admin, '2026-07-17', 'am_half');
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2026-07-01', '2028-06-30', 10.0, null));

        // 申請・承認の後に締める(締め済みの月の日には申請できないため)。
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

        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->actingAs($admin)
            ->get('/api/exports/attendance.xlsx?year_month=2026-06');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            rawurlencode('勤怠管理表_締め済み社員_2026年06月.xlsx'),
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            'Content-Disposition',
            $response->headers->get('Access-Control-Expose-Headers'),
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmpFile, $response->getContent());

        $spreadsheet = IOFactory::load($tmpFile);
        $sheet = $spreadsheet->getSheet(0);
        $this->assertSame(['勤怠管理表'], $spreadsheet->getSheetNames());
        $this->assertSame('2026年6月度 勤怠管理表', $sheet->getCell('A2')->getValue());
        $this->assertSame('社員番号', $sheet->getCell('G1')->getValue());
        $this->assertSame('氏名', $sheet->getCell('G4')->getValue());
        $this->assertSame('締め 済み　社員', $sheet->getCell('I4')->getValue());
        $this->assertSame('有給残日数', $sheet->getCell('H9')->getValue());
        $this->assertSame('8.5日', $sheet->getCell('H10')->getValue());
        $this->assertSame('日', $sheet->getCell('A12')->getValue());
        $this->assertSame('09:00', $sheet->getCell('C27')->getValue());
        $this->assertSame('000000', $sheet->getStyle('A2')->getFont()->getColor()->getRGB());
        $this->assertSame('000000', $sheet->getStyle('A12')->getFont()->getColor()->getRGB());
        $this->assertTrue($sheet->getPageSetup()->getHorizontalCentered());
        $this->assertTrue($sheet->getPageSetup()->getVerticalCentered());

        unlink($tmpFile);

        $this->assertDatabaseHas('stored_events', ['event_class' => 'export.created']);
        $event = EloquentStoredEvent::query()
            ->where('event_class', 'export.created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('attendance_xlsx', $event->event_properties['exportType']);
    }

    public function test_excel_paid_leave_balance_excludes_a_cancelled_usage(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create(['name' => '取消消化社員']);

        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));
        $this->approvePaidLeave($employee, $admin, '2026-06-22', 'full');
        $cancelledRequestId = $this->approvePaidLeave($employee, $admin, '2026-06-23', 'full');
        $this->actingAs($admin)->postJson("/api/paid-leave/requests/{$cancelledRequestId}/admin-cancel")->assertOk();

        // 前提: 取消した消化の行はcancelled = trueになっている。
        $this->assertTrue(PaidLeaveUsage::query()->where('paid_leave_request_id', $cancelledRequestId)->firstOrFail()->cancelled);

        $this->assertSame('9日', $this->closedJuneExcelPaidLeaveBalance($employee, $admin));
    }

    public function test_excel_paid_leave_balance_splits_a_usage_spanning_two_grants_by_allocation(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create(['name' => '複数付与社員']);

        // 有効期限が近い付与Aから先に充当される(付与A 0.5日 → 付与B 0.5日)。
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 0.5, null));
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2026-04-01', '2028-03-31', 10.0, null));
        $requestId = $this->approvePaidLeave($employee, $admin, '2026-06-22', 'full');

        // 前提: 1件の消化が2つの付与に0.5日ずつ充当されている。
        $usageId = PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->value('usage_id');
        $allocations = PaidLeaveUsageAllocation::query()->where('usage_id', $usageId)->get();
        $this->assertCount(2, $allocations);
        $this->assertSame([0.5, 0.5], $allocations->map(fn ($allocation) => (float) $allocation->allocated_days)->sort()->values()->all());

        $this->assertSame('9.5日', $this->closedJuneExcelPaidLeaveBalance($employee, $admin));
    }

    public function test_excel_paid_leave_balance_ignores_a_usage_after_the_month_end(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create(['name' => '翌月消化社員']);

        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));
        $this->approvePaidLeave($employee, $admin, '2026-06-22', 'full');
        $this->approvePaidLeave($employee, $admin, '2026-07-15', 'full');

        $this->assertSame('9日', $this->closedJuneExcelPaidLeaveBalance($employee, $admin));
    }

    public function test_excel_paid_leave_balance_excludes_a_revoked_grant(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create(['name' => '取消付与社員']);

        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2026-04-01', '2028-03-31', 5.0, null));
        $revokedGrantId = PaidLeaveGrant::query()
            ->where('user_id', $employee->id)
            ->whereDate('granted_on', '2026-04-01')
            ->value('id');
        app(CommandBus::class)->dispatch(new RevokePaidLeaveGrant($employee->id, $revokedGrantId, $admin->id, null));

        // 取り消した付与Bの5日は含めない。
        $this->assertSame('10日', $this->closedJuneExcelPaidLeaveBalance($employee, $admin));
    }

    public function test_excel_with_include_submitted_outputs_daily_rows_and_snapshot_totals_for_a_submitted_month(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => '提出済み社員']);
        $this->createMonthWithWorkedDay($employee, AttendanceMonthStatus::SUBMITTED, $this->submittedSnapshot());

        $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month=2026-06&include_submitted=1');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            rawurlencode('勤怠管理表_提出済み社員_2026年06月.xlsx'),
            $response->headers->get('Content-Disposition'),
        );

        $sheet = $this->loadSingleSheetWorkbook($response->getContent());
        $this->assertSame('2026年6月度 勤怠管理表', $sheet->getCell('A2')->getValue());
        // 6/15の行(12行目+15日)に実績の始業・終業が出る。
        $this->assertSame('09:00', $sheet->getCell('C27')->getValue());
        $this->assertSame('18:00', $sheet->getCell('D27')->getValue());
        // 集計はスナップショット由来(時間外120分、労働時間合計9600分、所定内=9600-120)。
        $this->assertEqualsWithDelta(120 / 1440, $sheet->getCell('A10')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(9480 / 1440, $sheet->getCell('E43')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(120 / 1440, $sheet->getCell('F43')->getValue(), 0.0001);

        $event = EloquentStoredEvent::query()->where('event_class', 'export.created')->latest('id')->firstOrFail();
        $this->assertSame('attendance_xlsx', $event->event_properties['exportType']);
        $this->assertSame('1', $event->event_properties['params']['include_submitted']);
    }

    public function test_excel_without_include_submitted_leaves_a_submitted_month_as_title_only(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => '提出済み社員']);
        $this->createMonthWithWorkedDay($employee, AttendanceMonthStatus::SUBMITTED, $this->submittedSnapshot());

        // 未指定・0・falseのいずれも承認済み・締め済みのみが対象になる(提出済みは対象外)。
        foreach (['', '&include_submitted=0', '&include_submitted=false'] as $query) {
            $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month=2026-06'.$query);

            $response->assertSuccessful();
            $sheet = $this->loadSingleSheetWorkbook($response->getContent());
            $this->assertSame('2026年6月度 勤怠管理表', $sheet->getCell('A2')->getValue());
            $this->assertNull($sheet->getCell('C27')->getValue());
            $this->assertNull($sheet->getCell('E43')->getValue());
        }
    }

    public function test_excel_include_submitted_still_excludes_returned_and_not_submitted_months(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        // 差戻し中の月はスナップショットを持つが対象外。
        $returnedEmployee = User::factory()->create(['name' => '差戻し社員']);
        $this->createMonthWithWorkedDay($returnedEmployee, AttendanceMonthStatus::RETURNED, $this->submittedSnapshot());

        // 未提出の月は対象外。
        $notSubmittedEmployee = User::factory()->create(['name' => '未提出社員']);
        $this->createMonthWithWorkedDay($notSubmittedEmployee, AttendanceMonthStatus::NOT_SUBMITTED, null);

        foreach ([$returnedEmployee, $notSubmittedEmployee] as $employee) {
            $response = $this->actingAs($admin)->get(
                '/api/exports/attendance.xlsx?year_month=2026-06&include_submitted=1&user_id[]='.$employee->id
            );

            $response->assertSuccessful();
            $sheet = $this->loadSingleSheetWorkbook($response->getContent());
            $this->assertSame('2026年6月度 勤怠管理表', $sheet->getCell('A2')->getValue());
            $this->assertNull($sheet->getCell('C27')->getValue());
            $this->assertNull($sheet->getCell('E43')->getValue());
        }
    }

    public function test_excel_include_submitted_zips_submitted_months_of_multiple_employees(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        foreach (['提出済みA', '提出済みB'] as $name) {
            $employee = User::factory()->create(['name' => $name]);
            $this->createMonthWithWorkedDay($employee, AttendanceMonthStatus::SUBMITTED, $this->submittedSnapshot());
        }

        $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month=2026-06&include_submitted=true');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/zip');

        $tmpZip = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmpZip, $response->getContent());

        $zip = new \ZipArchive;
        $zip->open($tmpZip);
        $this->assertSame(2, $zip->numFiles);
        foreach (['提出済みA', '提出済みB'] as $name) {
            $this->assertNotFalse($zip->locateName('勤怠管理表_'.$name.'_2026年06月.xlsx'));
        }
        $zip->close();
        unlink($tmpZip);
    }

    public function test_admin_export_excel_returns_zip_of_per_employee_workbooks_for_multiple_employees(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employeeNames = [];
        foreach (['社員A', '社員B'] as $name) {
            $employee = User::factory()->create(['name' => $name]);
            $employeeNames[] = $name;
            AttendanceMonth::query()->create([
                'user_id' => $employee->id,
                'year_month' => '2026-06',
                'status' => 'approved',
            ]);
        }

        $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month=2026-06');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString(
            rawurlencode('勤怠管理表_2026年06月.zip'),
            $response->headers->get('Content-Disposition'),
        );

        $tmpZip = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmpZip, $response->getContent());

        $zip = new \ZipArchive;
        $zip->open($tmpZip);
        $this->assertSame(2, $zip->numFiles);

        foreach ($employeeNames as $employeeName) {
            $entryName = '勤怠管理表_'.$employeeName.'_2026年06月.xlsx';
            $this->assertNotFalse($zip->locateName($entryName), "expected {$entryName} in zip");

            $xlsxContents = $zip->getFromName($entryName);
            $tmpXlsx = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($tmpXlsx, $xlsxContents);

            $spreadsheet = IOFactory::load($tmpXlsx);
            $this->assertSame(['勤怠管理表'], $spreadsheet->getSheetNames());

            unlink($tmpXlsx);
        }

        $zip->close();
        unlink($tmpZip);

        $event = EloquentStoredEvent::query()->where('event_class', 'export.created')->latest('id')->firstOrFail();
        $this->assertSame('attendance_xlsx_zip', $event->event_properties['exportType']);
    }

    public function test_non_admin_cannot_export_attendance_excel(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/api/exports/attendance.xlsx?year_month=2026-06')->assertForbidden();
    }

    public function test_global_attendance_export_scope_outputs_all_employees_when_user_id_is_omitted(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $outside = User::factory()->create(['name' => 'グループ外社員']);
        $this->createApprovedJuneMonth($inside);
        $this->createApprovedJuneMonth($outside);

        $response = $this->actingAs($admin)->get('/api/exports/attendance?year_month=2026-06');

        $response->assertSuccessful();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('グループ内社員', $csv);
        $this->assertStringContainsString('グループ外社員', $csv);
    }

    public function test_group_scoped_attendance_export_outputs_only_group_members_when_user_id_is_omitted(): void
    {
        [$parent, $child] = $this->createExportGroupTree();
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $outside = User::factory()->create(['name' => 'グループ外社員']);
        $this->addMembership($inside, $child);
        $this->createApprovedJuneMonth($inside);
        $this->createApprovedJuneMonth($outside);
        $exporter = $this->createGroupScopedExporter($child);

        $response = $this->actingAs($exporter)->get('/api/exports/attendance?year_month=2026-06');

        $response->assertSuccessful();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('グループ内社員', $csv);
        $this->assertStringNotContainsString('グループ外社員', $csv);
    }

    public function test_group_scoped_attendance_export_rejects_user_id_outside_the_permitted_group_with_403(): void
    {
        [$parent, $child] = $this->createExportGroupTree();
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $outside = User::factory()->create(['name' => 'グループ外社員']);
        $this->addMembership($inside, $child);
        $this->createApprovedJuneMonth($inside);
        $this->createApprovedJuneMonth($outside);
        $exporter = $this->createGroupScopedExporter($child);

        $this->actingAs($exporter)
            ->get('/api/exports/attendance?year_month=2026-06&user_id[]='.$outside->id)
            ->assertForbidden();
        $this->actingAs($exporter)
            ->get('/api/exports/attendance?year_month=2026-06&user_id[]='.$inside->id.'&user_id[]='.$outside->id)
            ->assertForbidden();
    }

    public function test_group_scoped_attendance_export_outputs_explicitly_requested_group_member(): void
    {
        [$parent, $child] = $this->createExportGroupTree();
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $outside = User::factory()->create(['name' => 'グループ外社員']);
        $this->addMembership($inside, $child);
        $this->createApprovedJuneMonth($inside);
        $this->createApprovedJuneMonth($outside);
        $exporter = $this->createGroupScopedExporter($child);

        $response = $this->actingAs($exporter)->get('/api/exports/attendance?year_month=2026-06&user_id[]='.$inside->id);

        $response->assertSuccessful();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('グループ内社員', $csv);
        $this->assertStringNotContainsString('グループ外社員', $csv);
    }

    public function test_group_scoped_excel_export_rejects_outside_user_id_and_outputs_only_group_member_when_omitted(): void
    {
        [$parent, $child] = $this->createExportGroupTree();
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $outside = User::factory()->create(['name' => 'グループ外社員']);
        $this->addMembership($inside, $child);
        $this->createApprovedJuneMonth($inside);
        $this->createApprovedJuneMonth($outside);
        $exporter = $this->createGroupScopedExporter($child);

        $this->actingAs($exporter)
            ->get('/api/exports/attendance.xlsx?year_month=2026-06&user_id[]='.$outside->id)
            ->assertForbidden();

        $response = $this->actingAs($exporter)->get('/api/exports/attendance.xlsx?year_month=2026-06');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            rawurlencode('勤怠管理表_グループ内社員_2026年06月.xlsx'),
            $response->headers->get('Content-Disposition'),
        );
        $this->loadSingleSheetWorkbook($response->getContent());
    }

    public function test_group_scoped_external_publish_rejects_outside_user_id_with_403(): void
    {
        [$parent, $child] = $this->createExportGroupTree();
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $outside = User::factory()->create(['name' => 'グループ外社員']);
        $this->addMembership($inside, $child);
        $this->createApprovedJuneMonth($inside);
        $this->createApprovedJuneMonth($outside);
        $exporter = $this->createGroupScopedExporter($child);

        $this->actingAs($exporter)->postJson('/api/exports/attendance/external-publish', [
            'year_month' => ['2026-06'],
            'user_id' => [$outside->id],
            'provider' => 'freee',
        ])->assertForbidden();
    }

    public function test_group_scope_with_include_descendants_outputs_members_of_child_groups(): void
    {
        [$parent, $child] = $this->createExportGroupTree();
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $this->addMembership($inside, $child);
        $this->createApprovedJuneMonth($inside);
        $exporter = $this->createGroupScopedExporter($parent, includeDescendants: true);

        $response = $this->actingAs($exporter)->get('/api/exports/attendance?year_month=2026-06&user_id[]='.$inside->id);

        $response->assertSuccessful();
        $this->assertStringContainsString('グループ内社員', $response->streamedContent());
    }

    public function test_month_can_export_is_true_for_any_employee_when_export_is_global(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create(['name' => 'グループ外社員']);
        $this->createApprovedJuneMonth($employee);

        $this->actingAs($admin)
            ->getJson('/api/attendance/months/2026-06?user_id='.$employee->id)
            ->assertOk()
            ->assertJsonPath('can_export', true);
    }

    public function test_month_can_export_is_true_for_a_member_of_the_group_scoped_exporter(): void
    {
        [$parent, $child] = $this->createExportGroupTree();
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $this->addMembership($inside, $child);
        $this->createApprovedJuneMonth($inside);
        $exporter = $this->createGroupScopedExporter($child);
        $this->grantGlobalAttendanceRead($exporter);

        $this->actingAs($exporter)
            ->getJson('/api/attendance/months/2026-06?user_id='.$inside->id)
            ->assertOk()
            ->assertJsonPath('can_export', true);
    }

    public function test_month_can_export_is_false_for_an_employee_outside_the_group_scoped_exporter(): void
    {
        [$parent, $child] = $this->createExportGroupTree();
        $inside = User::factory()->create(['name' => 'グループ内社員']);
        $outside = User::factory()->create(['name' => 'グループ外社員']);
        $this->addMembership($inside, $child);
        $this->createApprovedJuneMonth($inside);
        $this->createApprovedJuneMonth($outside);
        $exporter = $this->createGroupScopedExporter($child);
        $this->grantGlobalAttendanceRead($exporter);

        $this->actingAs($exporter)
            ->getJson('/api/attendance/months/2026-06?user_id='.$outside->id)
            ->assertOk()
            ->assertJsonPath('can_export', false);
    }

    public function test_month_can_export_is_false_for_own_month_without_attendance_export_permission(): void
    {
        $employee = User::factory()->create();
        $this->assignRole($employee, Role::query()->create(['code' => Role::EMPLOYEE, 'name' => '一般社員']));
        $this->createApprovedJuneMonth($employee);

        $this->actingAs($employee)
            ->getJson('/api/attendance/months/2026-06')
            ->assertOk()
            ->assertJsonPath('can_export', false);
    }

    /**
     * 2026-06の承認済み月次勤怠を作る(出力対象になる)。
     */
    private function createApprovedJuneMonth(User $employee): void
    {
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'approved',
        ]);
    }

    /**
     * 親グループ(本社)と子グループ(支社)を作る。
     *
     * @return array{0: string, 1: string} 親グループID・子グループID
     */
    private function createExportGroupTree(): array
    {
        $typeId = DB::table('group_types')->insertGetId([
            'code' => 'EXPORT_SCOPE', 'name' => '出力範囲', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $parent = (string) Str::uuid();
        $child = (string) Str::uuid();
        DB::table('groups')->insert([
            ['id' => $parent, 'group_type_id' => $typeId, 'name' => '本社', 'code' => 'EXPORT_HQ', 'parent_group_id' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $child, 'group_type_id' => $typeId, 'name' => '支社', 'code' => 'EXPORT_BRANCH', 'parent_group_id' => $parent, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$parent, $child];
    }

    private function addMembership(User $user, string $groupId): void
    {
        DB::table('memberships')->insert([
            'user_id' => $user->id, 'group_id' => $groupId, 'membership_kind' => 'member', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * attendance.exportをグループスコープ(global の割当は持たせない)だけで持つ利用者を作る。
     * 権限の付与は既存のアクセス制御テストと同じく、テーブルへ直接用意する。
     */
    private function createGroupScopedExporter(string $scopeGroupId, bool $includeDescendants = false): User
    {
        $exporter = User::factory()->create();
        $role = Role::query()->firstOrCreate(
            ['code' => 'EXPORT_GROUP_TEST'],
            ['name' => '勤怠出力(グループ)', 'status' => 'active'],
        );

        DB::table('permissions')->updateOrInsert(['code' => 'attendance.export'], [
            'resource' => 'attendance',
            'action' => 'export',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('code', 'attendance.export')->value('id');
        DB::table('permission_scope_types')->insertOrIgnore(['permission_id' => $permissionId, 'scope_type' => 'group']);
        DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $role->id]);
        DB::table('role_assignments')->insert([
            'id' => (string) Str::uuid(),
            'subject_type' => 'user',
            'subject_id' => $exporter->id,
            'role_id' => $role->id,
            'scope_type' => 'group',
            'scope_group_id' => $scopeGroupId,
            'include_descendants' => $includeDescendants,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $exporter;
    }

    /**
     * attendance.readを全社(global)で持つ別ロールの割当を追加する(他人の月次勤怠を閲覧するために必要。
     * attendance.exportのスコープ(グループ)は変えない)。
     */
    private function grantGlobalAttendanceRead(User $user): void
    {
        $role = Role::query()->firstOrCreate(
            ['code' => 'ATTENDANCE_READ_TEST'],
            ['name' => '勤怠閲覧(全社・テスト)', 'status' => 'active'],
        );

        DB::table('permissions')->updateOrInsert(['code' => 'attendance.read'], [
            'resource' => 'attendance',
            'action' => 'read',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('code', 'attendance.read')->value('id');
        DB::table('permission_scope_types')->insertOrIgnore(['permission_id' => $permissionId, 'scope_type' => 'global']);
        DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $role->id]);
        DB::table('role_assignments')->insert([
            'id' => (string) Str::uuid(),
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'global',
            'scope_group_id' => null,
            'include_descendants' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_generic_tsv_format_uses_tab_delimiter_japanese_headers_and_colon_time(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

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
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

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
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

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
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

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
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)
            ->get('/api/exports/attendance?year_month=2026-06&format=yayoi')
            ->assertStatus(422);
    }

    /**
     * 6/15に実績のある勤怠日と、指定した状態・スナップショットの月次勤怠を作る。
     *
     * @param  array<string, int>|null  $snapshot
     */
    private function createMonthWithWorkedDay(User $employee, string $status, ?array $snapshot): void
    {
        AttendanceDay::query()->create([
            'user_id' => $employee->id,
            'work_date' => '2026-06-15',
            'status' => 'clocked_out',
            'actual_start_at' => '2026-06-15 09:00:00',
            'actual_end_at' => '2026-06-15 18:00:00',
            'work_type' => 'normal',
            'day_classification' => 'working_day',
        ]);

        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => $status,
            'snapshot_json' => $snapshot,
        ]);
    }

    /** @return array<string, int> */
    private function submittedSnapshot(): array
    {
        return [
            'work_minutes' => 9600,
            'prescribed_work_minutes' => 9600,
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 120,
            'late_night_work_minutes' => 60,
            'legal_holiday_work_minutes' => 0,
            'prescribed_holiday_work_minutes' => 0,
        ];
    }

    private function loadSingleSheetWorkbook(string $contents): Worksheet
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmpFile, $contents);

        $spreadsheet = IOFactory::load($tmpFile);
        unlink($tmpFile);

        $this->assertSame(['勤怠管理表'], $spreadsheet->getSheetNames());

        return $spreadsheet->getSheet(0);
    }

    /**
     * 有給を申請し承認する(消化記録・残数は承認のイベントを受けて口座集約側が作る)。
     *
     * @return string 申請ID
     */
    private function approvePaidLeave(User $employee, User $approver, string $date, string $leaveType): string
    {
        $this->createWorkingDayShift($employee, $date);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => $date,
            'leave_type' => $leaveType,
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        return $requestId;
    }

    /**
     * 2026-06を締め済みにしてExcelを出力し、有給残日数(H10)を返す。
     */
    private function closedJuneExcelPaidLeaveBalance(User $employee, User $admin): mixed
    {
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'closed',
            'snapshot_json' => $this->submittedSnapshot(),
        ]);

        $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month=2026-06');

        $response->assertSuccessful();

        return $this->loadSingleSheetWorkbook($response->getContent())->getCell('H10')->getValue();
    }

    private function createWorkingDayShift(User $user, string $date): void
    {
        $calendar = CompanyCalendar::query()->firstOrCreate(['name' => '2026年度'], ['week_starts_on' => 1]);
        if (! $calendar->years()->exists()) {
            $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        }
        $workStyle = WorkStyle::query()->firstOrCreate(['code' => 'standard-'.$user->id], [
            'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }
}
