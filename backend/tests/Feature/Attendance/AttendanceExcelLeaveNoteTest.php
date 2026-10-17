<?php

namespace Tests\Feature\Attendance;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\AttendanceMonth;
use App\Models\EmployeeCalendarEntry;
use App\Models\Role;
use App\Models\SpecialLeaveType;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\CompensatoryLeave\CompensatoryLeaveTestHelpers;
use Tests\Feature\SpecialLeave\SpecialLeaveTestHelpers;
use Tests\TestCase;

/**
 * 勤怠管理表(Excel)の日別の備考(K列)に、その日の休暇が種類(特別休暇は種別名)と単位つきで出ることを、
 * 休暇の申請・承認・差戻し・取消のAPI/Commandから通して確かめる(原則2・16)。
 * 日付 D の行は 12+D 行目(見出しは12行目)。対象月は2026-09。
 */
class AttendanceExcelLeaveNoteTest extends TestCase
{
    use CompensatoryLeaveTestHelpers;
    use RefreshDatabase;
    use SpecialLeaveTestHelpers;

    private const MONTH = '2026-09';

    /** 代休の付与の元になる休日出勤(2026-08)。1日ずつ1件の代休付与になる。 */
    private const HOLIDAY_WORK_DATES = ['2026-08-08', '2026-08-09', '2026-08-15', '2026-08-16', '2026-08-22', '2026-08-23', '2026-08-29', '2026-08-30'];

    /**
     * 利用者(代休の付与済み・有給の付与済み)と承認者・管理者を作る。
     *
     * @return array{0: User, 1: User, 2: User} [利用者, 承認者, 管理者]
     */
    private function setUpEmployee(): array
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->holidayWorkGrantedAndConfirmed($employee, self::HOLIDAY_WORK_DATES);
        // 代休の半休を申請できるよう取得単位を半日単位にする(付与済みの代休は変わらない)。
        $this->enableCompensatoryLeave('half_day');
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2026-07-01', '2028-06-30', 10.0, null));

        return [$employee, $approver, $admin];
    }

    /** 誕生日休暇の種別を作り、利用者に付与する(残数は承認時に消化する)。 */
    private function createSpecialType(User $employee): SpecialLeaveType
    {
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true, 'requires_grant' => true]);
        $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null, 'granted_days' => 3,
        ]);

        return $type;
    }

    /**
     * 勤務予定が無い日だけ作る。同じ日に2種類の休暇を申請するため、日付の存在を日付単位で確かめる
     * (CompensatoryLeaveTestHelpers::makeCompensatoryWorkingDayShift は文字列の日付で探すため二重に作れない)。
     */
    private function ensureWorkingDay(User $employee, string $date): void
    {
        $exists = EmployeeCalendarEntry::query()
            ->where('user_id', $employee->id)
            ->whereDate('work_date', $date)
            ->exists();
        if (! $exists) {
            $this->makeCompensatoryWorkingDayShift($employee, WorkStyle::query()->firstOrFail(), $date);
        }
    }

    private function requestSpecialLeave(User $employee, User $approver, SpecialLeaveType $type, string $date): string
    {
        $this->ensureWorkingDay($employee, $date);

        return $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => $date,
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
    }

    private function requestPaidLeave(User $employee, User $approver, string $date, string $leaveType): string
    {
        $this->ensureWorkingDay($employee, $date);

        return $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => $date,
            'leave_type' => $leaveType,
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
    }

    private function approvePaidLeave(User $approver, string $requestId): void
    {
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();
    }

    private function approveCompensatoryLeave(User $approver, string $requestId): void
    {
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();
    }

    private function approveSpecialLeave(User $approver, string $requestId): void
    {
        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    /** 対象月を承認済みで作る(休暇の申請は、すべて作り終えた後に行う。締め後は申請できないため)。 */
    private function closeMonth(User $employee): void
    {
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => self::MONTH,
            'status' => 'approved',
        ]);
    }

    /** 勤怠管理表(Excel)のシートを読み込む。 */
    private function exportSheet(User $admin): Worksheet
    {
        $response = $this->actingAs($admin)->get('/api/exports/attendance.xlsx?year_month='.self::MONTH);
        $response->assertSuccessful();

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmpFile, $response->getContent());
        $spreadsheet = IOFactory::load($tmpFile);
        unlink($tmpFile);

        return $spreadsheet->getSheet(0);
    }

    /** 日付Dの備考(K列)。日付Dの行は12+D行目。 */
    private function noteOn(Worksheet $sheet, int $day): string
    {
        return (string) $sheet->getCell('K'.(12 + $day))->getValue();
    }

    public function test_approved_compensatory_leave_full_day_is_written_in_the_note(): void
    {
        [$employee, $approver, $admin] = $this->setUpEmployee();

        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-01');
        $this->approveCompensatoryLeave($approver, $requestId);
        $this->closeMonth($employee);

        $this->assertSame('代休(全休)', $this->noteOn($this->exportSheet($admin), 1));
    }

    public function test_approved_special_leave_is_written_with_its_type_name(): void
    {
        [$employee, $approver, $admin] = $this->setUpEmployee();
        $type = $this->createSpecialType($employee);

        $requestId = $this->requestSpecialLeave($employee, $approver, $type, '2026-09-02');
        $this->approveSpecialLeave($approver, $requestId);
        $this->closeMonth($employee);

        $this->assertSame('誕生日休暇(全休)', $this->noteOn($this->exportSheet($admin), 2));
    }

    public function test_am_paid_leave_and_pm_compensatory_leave_on_the_same_day_are_joined_in_unit_order_even_when_the_compensatory_was_requested_first(): void
    {
        [$employee, $approver, $admin] = $this->setUpEmployee();

        $compensatoryId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-03', 'pm_half');
        $paidId = $this->requestPaidLeave($employee, $approver, '2026-09-03', 'am_half');
        $this->approvePaidLeave($approver, $paidId);
        $this->approveCompensatoryLeave($approver, $compensatoryId);
        $this->closeMonth($employee);

        $this->assertSame('有給休暇(午前半休)・代休(午後半休)', $this->noteOn($this->exportSheet($admin), 3));
    }

    public function test_submitted_compensatory_leave_is_written_with_the_submitted_suffix(): void
    {
        [$employee, $approver, $admin] = $this->setUpEmployee();

        $this->requestCompensatoryLeave($employee, $approver, '2026-09-04');
        $this->closeMonth($employee);

        $this->assertSame('代休(全休・申請中)', $this->noteOn($this->exportSheet($admin), 4));
    }

    public function test_returned_compensatory_leave_is_not_written_in_the_note(): void
    {
        [$employee, $approver, $admin] = $this->setUpEmployee();

        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-05');
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/return", [
            'comment' => '日付を見直してください',
        ])->assertOk();
        $this->closeMonth($employee);

        $this->assertSame('', $this->noteOn($this->exportSheet($admin), 5));
    }

    public function test_cancelled_approved_special_leave_is_not_written_in_the_note(): void
    {
        [$employee, $approver, $admin] = $this->setUpEmployee();
        $type = $this->createSpecialType($employee);

        $requestId = $this->requestSpecialLeave($employee, $approver, $type, '2026-09-06');
        $this->approveSpecialLeave($approver, $requestId);
        $this->actingAs($employee)->postJson("/api/special-leave/requests/{$requestId}/cancel")->assertOk();
        $this->closeMonth($employee);

        $this->assertSame('', $this->noteOn($this->exportSheet($admin), 6));
    }

    public function test_resubmitted_compensatory_leave_after_a_return_is_written_as_submitted(): void
    {
        [$employee, $approver, $admin] = $this->setUpEmployee();

        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-07');
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/return", [
            'comment' => '日付を見直してください',
        ])->assertOk();
        $workflowRequestId = WorkflowRequest::query()->where('subject_id', $requestId)->value('id');
        $this->actingAs($employee)->postJson("/api/workflow-requests/{$workflowRequestId}/submit")->assertSuccessful();
        $this->closeMonth($employee);

        $this->assertSame('代休(全休・申請中)', $this->noteOn($this->exportSheet($admin), 7));
    }

    public function test_note_joins_the_attendance_day_note_after_the_leave_with_the_separator(): void
    {
        [$employee, $approver, $admin] = $this->setUpEmployee();

        // 勤怠日の備考(勤怠日は休暇の申請より前に作る。休暇は既存の勤怠日へ反映される)。
        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-09-08',
            'note' => '外出先で作業',
            'reason' => 'テスト備考',
        ])->assertCreated();
        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-08');
        $this->approveCompensatoryLeave($approver, $requestId);
        $this->closeMonth($employee);

        $this->assertSame('代休(全休) / 外出先で作業', $this->noteOn($this->exportSheet($admin), 8));
    }
}
