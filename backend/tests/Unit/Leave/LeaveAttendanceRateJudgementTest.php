<?php

namespace Tests\Unit\Leave;

use App\Domain\Leave\Support\LeaveAttendanceRateJudgement as J;
use PHPUnit\Framework\TestCase;

/**
 * 出勤率の日単位の判定(全休・部分休暇・出勤として数えるか)の単体テスト(仕様確定事項F・論点9・仕様確定事項I)。
 */
class LeaveAttendanceRateJudgementTest extends TestCase
{
    public function test_a_day_without_leave_has_no_full_or_partial_kinds(): void
    {
        $this->assertSame(['full' => [], 'partial' => []], J::coverage([]));
    }

    public function test_a_full_day_leave_is_full_for_any_kind(): void
    {
        $this->assertSame(
            ['full' => ['paid'], 'partial' => []],
            J::coverage([['kind' => J::KIND_PAID, 'unit' => 'full']]),
        );
        $this->assertSame(
            ['full' => ['compensatory'], 'partial' => []],
            J::coverage([['kind' => J::KIND_COMPENSATORY, 'unit' => 'full']]),
        );
    }

    public function test_a_single_half_day_is_partial_and_not_full(): void
    {
        $this->assertSame(
            ['full' => [], 'partial' => ['paid']],
            J::coverage([['kind' => J::KIND_PAID, 'unit' => 'am_half']]),
        );
        $this->assertSame(
            ['full' => [], 'partial' => ['special']],
            J::coverage([['kind' => J::KIND_SPECIAL, 'unit' => 'pm_half']]),
        );
    }

    public function test_am_half_and_pm_half_of_the_same_kind_make_a_full_day(): void
    {
        $this->assertSame(
            ['full' => ['paid'], 'partial' => []],
            J::coverage([
                ['kind' => J::KIND_PAID, 'unit' => 'am_half'],
                ['kind' => J::KIND_PAID, 'unit' => 'pm_half'],
            ]),
        );
    }

    public function test_am_half_and_pm_half_of_different_kinds_make_a_full_day_with_both_kinds(): void
    {
        // 午前有給+午後代休は全休として扱う(所定を半分にしない。仕様確定事項I)。
        $this->assertSame(
            ['full' => [J::KIND_COMPENSATORY, J::KIND_PAID], 'partial' => []],
            J::coverage([
                ['kind' => J::KIND_PAID, 'unit' => 'am_half'],
                ['kind' => J::KIND_COMPENSATORY, 'unit' => 'pm_half'],
            ]),
        );
    }

    public function test_am_half_with_hourly_is_partial_and_not_full(): void
    {
        $this->assertSame(
            ['full' => [], 'partial' => ['paid']],
            J::coverage([
                ['kind' => J::KIND_PAID, 'unit' => 'am_half'],
                ['kind' => J::KIND_PAID, 'unit' => 'hourly'],
            ]),
        );
    }

    public function test_hourly_leave_is_partial_even_when_combined_with_a_half_day_pair(): void
    {
        $this->assertSame(
            ['full' => [J::KIND_COMPENSATORY, J::KIND_PAID], 'partial' => [J::KIND_SPECIAL]],
            J::coverage([
                ['kind' => J::KIND_PAID, 'unit' => 'am_half'],
                ['kind' => J::KIND_COMPENSATORY, 'unit' => 'pm_half'],
                ['kind' => J::KIND_SPECIAL, 'unit' => 'hourly'],
            ]),
        );
    }

    public function test_a_day_already_attended_counts_as_attended(): void
    {
        $this->assertTrue(J::countsAsAttended(true, [], [], [J::KIND_PAID]));
    }

    public function test_any_full_day_leave_kind_counts_as_attended(): void
    {
        $this->assertTrue(J::countsAsAttended(false, [J::KIND_COMPENSATORY], [], [J::KIND_PAID]));
    }

    public function test_a_partial_paid_leave_counts_as_attended_when_paid_is_counted(): void
    {
        $this->assertTrue(J::countsAsAttended(false, [], [J::KIND_PAID], [J::KIND_PAID]));
    }

    public function test_a_partial_special_leave_does_not_count_when_only_paid_is_counted(): void
    {
        // 出勤率Assessorは半休・時間休を有給のみ数える。
        $this->assertFalse(J::countsAsAttended(false, [], [J::KIND_SPECIAL], [J::KIND_PAID]));
    }

    public function test_a_partial_special_leave_counts_when_paid_and_special_are_counted(): void
    {
        // 特別休暇の付与の出勤率(GrantScheduledSpecialLeaveHandler)は有給・特別休暇の半休・時間休を数える。
        $this->assertTrue(J::countsAsAttended(false, [], [J::KIND_SPECIAL], [J::KIND_PAID, J::KIND_SPECIAL]));
    }

    public function test_a_partial_compensatory_leave_never_counts(): void
    {
        $this->assertFalse(J::countsAsAttended(false, [], [J::KIND_COMPENSATORY], [J::KIND_PAID, J::KIND_SPECIAL]));
    }

    public function test_a_day_without_attendance_or_leave_does_not_count(): void
    {
        $this->assertFalse(J::countsAsAttended(false, [], [], [J::KIND_PAID, J::KIND_SPECIAL]));
    }
}
