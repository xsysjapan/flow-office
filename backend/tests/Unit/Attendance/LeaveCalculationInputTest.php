<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\Support\LeaveCalculationInput;
use App\Models\AttendanceDayLeave;
use App\Models\PaidLeaveType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LeaveCalculationInput(休暇ビュー→日次計算への入力変換)の検証。DB非依存。
 *
 * 入力は休暇ビュー(attendance_day_leaves)の有効な休暇の行(leave_kind・unit・minutes)。勤怠日の作業内容
 * (work_type)と消化記録は読まない(論点10)。単一休暇の期待値は、休暇の日数・時間休分数・所定労働時間の規則
 * (LeaveCalculationInput のdocblock参照)で定める:
 * - 有給・特別の日数: 全休=1.0、午前・午後の半休=0.5。代休は日数に数えない。
 * - 有給・特別の時間休分数: 時間休(hourly)のminutesの合計。代休は数えない。
 * - 所定労働時間: 半休が1つだけの日は intdiv(P, 2)。全休・午前+午後の半休がそろう日・時間休・通常日はPのまま。
 */
class LeaveCalculationInputTest extends TestCase
{
    private const P = 480;

    /**
     * 単一休暇の期待値 [paidLeaveDays, specialLeaveDays, paidLeaveMinutes, specialLeaveMinutes,
     * effectivePrescribedMinutes, isFullDayLeave]。
     *
     * @return array<string, array{0: string, 1: string, 2: ?int, 3: array{0: float, 1: float, 2: int, 3: int, 4: int, 5: bool}}>
     */
    public static function singleLeaveCases(): array
    {
        $paid = AttendanceDayLeave::KIND_PAID;
        $special = AttendanceDayLeave::KIND_SPECIAL;
        $compensatory = AttendanceDayLeave::KIND_COMPENSATORY;

        return [
            // 有給: 全休(日数1.0)、半休(0.5, 所定はintdiv)、時間休(hourly分数)
            'paid full' => [$paid, PaidLeaveType::FULL, null, [1.0, 0.0, 0, 0, 480, true]],
            'paid am_half' => [$paid, PaidLeaveType::AM_HALF, null, [0.5, 0.0, 0, 0, 240, false]],
            'paid pm_half' => [$paid, PaidLeaveType::PM_HALF, null, [0.5, 0.0, 0, 0, 240, false]],
            'paid hourly 90 minutes' => [$paid, PaidLeaveType::HOURLY, 90, [0.0, 0.0, 90, 0, 480, false]],

            // 特別休暇: 日数・時間休分数は有給と同じ規則で特別休暇側へ入る
            'special full' => [$special, PaidLeaveType::FULL, null, [0.0, 1.0, 0, 0, 480, true]],
            'special am_half' => [$special, PaidLeaveType::AM_HALF, null, [0.0, 0.5, 0, 0, 240, false]],
            'special pm_half' => [$special, PaidLeaveType::PM_HALF, null, [0.0, 0.5, 0, 0, 240, false]],
            'special hourly 60 minutes' => [$special, PaidLeaveType::HOURLY, 60, [0.0, 0.0, 0, 60, 480, false]],

            // 代休: 日数・時間休分数に数えない。全休・半休の所定労働時間の扱いは他の休暇と同じ
            'compensatory full' => [$compensatory, PaidLeaveType::FULL, null, [0.0, 0.0, 0, 0, 480, true]],
            'compensatory am_half' => [$compensatory, PaidLeaveType::AM_HALF, null, [0.0, 0.0, 0, 0, 240, false]],
            'compensatory pm_half' => [$compensatory, PaidLeaveType::PM_HALF, null, [0.0, 0.0, 0, 0, 240, false]],
            'compensatory hourly 120 minutes' => [$compensatory, PaidLeaveType::HOURLY, 120, [0.0, 0.0, 0, 0, 480, false]],
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: int, 3: int, 4: int, 5: bool}  $expected
     */
    #[DataProvider('singleLeaveCases')]
    public function test_single_leave_matches_current_calculator(string $kind, string $unit, ?int $minutes, array $expected): void
    {
        $input = LeaveCalculationInput::from(
            [['leave_kind' => $kind, 'unit' => $unit, 'minutes' => $minutes]],
            self::P,
        );

        $this->assertSame($expected[0], $input->paidLeaveDays);
        $this->assertSame($expected[1], $input->specialLeaveDays);
        $this->assertSame($expected[2], $input->paidLeaveMinutes);
        $this->assertSame($expected[3], $input->specialLeaveMinutes);
        $this->assertSame($expected[4], $input->effectivePrescribedMinutes);
        $this->assertSame($expected[5], $input->isFullDayLeave);
    }

    public function test_am_half_paid_and_pm_half_compensatory_is_full_day_with_prescribed_minutes(): void
    {
        // 午前半休と午後半休がそろう日は全休と同じ(所定はP)。有給の半休分の日数だけ数える。
        $input = LeaveCalculationInput::from([
            ['leave_kind' => AttendanceDayLeave::KIND_PAID, 'unit' => PaidLeaveType::AM_HALF, 'minutes' => null],
            ['leave_kind' => AttendanceDayLeave::KIND_COMPENSATORY, 'unit' => PaidLeaveType::PM_HALF, 'minutes' => null],
        ], self::P);

        $this->assertSame(0.5, $input->paidLeaveDays);
        $this->assertSame(0.0, $input->specialLeaveDays);
        $this->assertSame(0, $input->paidLeaveMinutes);
        $this->assertSame(self::P, $input->effectivePrescribedMinutes);
        $this->assertTrue($input->isFullDayLeave);
    }

    public function test_am_half_and_pm_half_paid_counts_one_day_and_full_day(): void
    {
        $input = LeaveCalculationInput::from([
            ['leave_kind' => AttendanceDayLeave::KIND_PAID, 'unit' => PaidLeaveType::AM_HALF, 'minutes' => null],
            ['leave_kind' => AttendanceDayLeave::KIND_PAID, 'unit' => PaidLeaveType::PM_HALF, 'minutes' => null],
        ], self::P);

        $this->assertSame(1.0, $input->paidLeaveDays);
        $this->assertSame(self::P, $input->effectivePrescribedMinutes);
        $this->assertTrue($input->isFullDayLeave);
    }

    public function test_full_day_with_hourly_leave_counts_both(): void
    {
        // 全休+時間休: 全休の日数と時間休の分数を両方数える。所定はP、全休の日。
        $input = LeaveCalculationInput::from([
            ['leave_kind' => AttendanceDayLeave::KIND_PAID, 'unit' => PaidLeaveType::FULL, 'minutes' => null],
            ['leave_kind' => AttendanceDayLeave::KIND_PAID, 'unit' => PaidLeaveType::HOURLY, 'minutes' => 90],
        ], self::P);

        $this->assertSame(1.0, $input->paidLeaveDays);
        $this->assertSame(90, $input->paidLeaveMinutes);
        $this->assertSame(self::P, $input->effectivePrescribedMinutes);
        $this->assertTrue($input->isFullDayLeave);
    }

    public function test_special_am_pm_halves_with_paid_hourly(): void
    {
        $input = LeaveCalculationInput::from([
            ['leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'unit' => PaidLeaveType::AM_HALF, 'minutes' => null],
            ['leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'unit' => PaidLeaveType::PM_HALF, 'minutes' => null],
            ['leave_kind' => AttendanceDayLeave::KIND_PAID, 'unit' => PaidLeaveType::HOURLY, 'minutes' => 30],
        ], self::P);

        $this->assertSame(1.0, $input->specialLeaveDays);
        $this->assertSame(0.0, $input->paidLeaveDays);
        $this->assertSame(30, $input->paidLeaveMinutes);
        $this->assertSame(0, $input->specialLeaveMinutes);
        $this->assertSame(self::P, $input->effectivePrescribedMinutes);
        $this->assertTrue($input->isFullDayLeave);
    }

    public function test_hourly_leaves_of_paid_and_special_are_summed_separately(): void
    {
        $input = LeaveCalculationInput::from([
            ['leave_kind' => AttendanceDayLeave::KIND_PAID, 'unit' => PaidLeaveType::HOURLY, 'minutes' => 60],
            ['leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'unit' => PaidLeaveType::HOURLY, 'minutes' => 30],
        ], self::P);

        $this->assertSame(60, $input->paidLeaveMinutes);
        $this->assertSame(30, $input->specialLeaveMinutes);
        $this->assertSame(self::P, $input->effectivePrescribedMinutes);
        $this->assertFalse($input->isFullDayLeave);
    }

    public function test_single_half_uses_half_prescribed_minutes_even_with_odd_prescribed(): void
    {
        // 現行 :160 の intdiv(P, 2) と同じ(奇数のPは切り捨て)
        $input = LeaveCalculationInput::from([
            ['leave_kind' => AttendanceDayLeave::KIND_PAID, 'unit' => PaidLeaveType::AM_HALF, 'minutes' => null],
        ], 481);

        $this->assertSame(240, $input->effectivePrescribedMinutes);
    }

    public function test_no_leaves(): void
    {
        $input = LeaveCalculationInput::from([], self::P);

        $this->assertSame(0.0, $input->paidLeaveDays);
        $this->assertSame(0.0, $input->specialLeaveDays);
        $this->assertSame(0, $input->paidLeaveMinutes);
        $this->assertSame(0, $input->specialLeaveMinutes);
        $this->assertSame(self::P, $input->effectivePrescribedMinutes);
        $this->assertFalse($input->isFullDayLeave);
    }
}
