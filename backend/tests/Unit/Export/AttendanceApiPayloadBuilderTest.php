<?php

namespace Tests\Unit\Export;

use App\Domain\Export\Services\AttendanceApi\FreeeAttendanceApiPayloadBuilder;
use App\Models\AttendanceMonth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * フェーズ2: AttendanceApiPayloadBuilder実装(freee)の単体テスト。勤怠のAPIプッシュ連携は
 * freeeのみ対応する(MoneyForwardには外部から勤怠データをプッシュする公開APIが存在しない。
 * docs/notes/moneyforward-api-investigation.md)。docs/33-usecases-attendance-external-api.md参照。
 */
class AttendanceApiPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function buildMonth(): AttendanceMonth
    {
        $employee = User::factory()->create();

        return AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'closed',
            'snapshot_json' => [
                'work_minutes' => 9600,
                'prescribed_work_minutes' => 9600,
                'statutory_within_overtime_minutes' => 60,
                'statutory_excess_overtime_minutes' => 120,
                'late_night_work_minutes' => 30,
                'legal_holiday_work_minutes' => 90,
                'prescribed_holiday_work_minutes' => 45,
                'absence_days' => 1.5,
                'paid_leave_days' => 2.0,
            ],
        ]);
    }

    public function test_freee_builder_produces_expected_structure(): void
    {
        $month = $this->buildMonth();
        $payload = (new FreeeAttendanceApiPayloadBuilder)->build($month, '4001', '999');

        $this->assertSame([
            'employee_id' => '4001',
            'year' => '2026',
            'month' => '6',
        ], $payload['_path']);
        $this->assertSame(999, $payload['company_id']);
        $this->assertSame(9600, $payload['total_work_mins']);
        $this->assertSame(9600, $payload['total_normal_work_mins']);
        $this->assertSame(60, $payload['total_excess_statutory_work_mins']);
        $this->assertSame(60, $payload['total_actual_excess_statutory_work_mins']);
        $this->assertSame(120, $payload['total_overtime_work_mins']);
        $this->assertSame(90, $payload['total_holiday_work_mins']);
        $this->assertSame(45, $payload['total_prescribed_holiday_work_mins']);
        $this->assertSame(30, $payload['total_latenight_work_mins']);
        $this->assertSame(1.5, $payload['num_absences']);
        $this->assertSame(1.5, $payload['num_absences_for_deduction']);
        $this->assertSame(2.0, $payload['num_paid_holidays']);
    }

    /**
     * フレックスの社員が所定休日に120分勤務した月。所定休日の所定内法定内(120)は全体の所定内法定内(600)に入るが、
     * freeeでは所定休日労働(total_prescribed_holiday_work_mins=120)と二重計上になるため、平日の所定内法定内(480)を送る。
     */
    public function test_freee_builder_excludes_prescribed_holiday_work_from_total_normal_work_mins(): void
    {
        $month = $this->buildMonthWithSnapshot([
            'weekday_prescribed_statutory_within_work_minutes' => 480,
            'prescribed_statutory_within_work_minutes' => 600,
            'prescribed_work_minutes' => 960,
            'prescribed_holiday_work_minutes' => 120,
        ]);

        $payload = (new FreeeAttendanceApiPayloadBuilder)->build($month, '1', '100');

        $this->assertSame(480, $payload['total_normal_work_mins']);
        $this->assertSame(120, $payload['total_prescribed_holiday_work_mins']);
    }

    /** 平日の所定内法定内キーが無い(5区分移行後・平日キー導入前)snapshotは全体の所定内法定内(600)を使う。 */
    public function test_freee_builder_uses_prescribed_statutory_within_when_weekday_key_is_missing(): void
    {
        $month = $this->buildMonthWithSnapshot([
            'prescribed_statutory_within_work_minutes' => 600,
            'prescribed_work_minutes' => 960,
        ]);

        $payload = (new FreeeAttendanceApiPayloadBuilder)->build($month, '1', '100');

        $this->assertSame(600, $payload['total_normal_work_mins']);
    }

    /** @param  array<string, int>  $snapshot */
    private function buildMonthWithSnapshot(array $snapshot): AttendanceMonth
    {
        $employee = User::factory()->create();

        return AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'closed',
            'snapshot_json' => $snapshot,
        ]);
    }

    public function test_freee_builder_requires_company_id(): void
    {
        $month = $this->buildMonth();

        $this->expectException(\RuntimeException::class);

        (new FreeeAttendanceApiPayloadBuilder)->build($month, '4001', null);
    }
}
