<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\Support\FullDayLeavePolicy;
use App\Models\PaidLeaveType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FullDayLeavePolicy(その日が全休か)の検証。DB非依存。
 * 全休: 全休の休暇がある、または午前半休と午後半休が種類を問わずそろう(仕様確定事項I・論点9)。
 */
class FullDayLeavePolicyTest extends TestCase
{
    /** @return array{leave_kind: string, unit: string} */
    private function leave(string $unit, string $kind = 'paid'): array
    {
        return ['leave_kind' => $kind, 'unit' => $unit];
    }

    /** @return array<string, array{0: list<array{leave_kind: string, unit: string}>, 1: bool}> */
    public static function fullDayCases(): array
    {
        $full = PaidLeaveType::FULL;
        $am = PaidLeaveType::AM_HALF;
        $pm = PaidLeaveType::PM_HALF;
        $hourly = PaidLeaveType::HOURLY;

        return [
            '休暇なし' => [[], false],
            '全休(有給)' => [[['leave_kind' => 'paid', 'unit' => $full]], true],
            '全休(特別休暇)' => [[['leave_kind' => 'special', 'unit' => $full]], true],
            '全休(代休)' => [[['leave_kind' => 'compensatory', 'unit' => $full]], true],
            '午前半休だけ' => [[['leave_kind' => 'paid', 'unit' => $am]], false],
            '午後半休だけ' => [[['leave_kind' => 'paid', 'unit' => $pm]], false],
            '午前半休が2つ(同じ半休)' => [[['leave_kind' => 'paid', 'unit' => $am], ['leave_kind' => 'special', 'unit' => $am]], false],
            '午前半休(有給)+午後半休(特別休暇)' => [[['leave_kind' => 'paid', 'unit' => $am], ['leave_kind' => 'special', 'unit' => $pm]], true],
            '午前半休(代休)+午後半休(代休)' => [[['leave_kind' => 'compensatory', 'unit' => $am], ['leave_kind' => 'compensatory', 'unit' => $pm]], true],
            '時間休だけ' => [[['leave_kind' => 'paid', 'unit' => $hourly]], false],
            '時間休+午前半休(半休が1つ)' => [[['leave_kind' => 'paid', 'unit' => $hourly], ['leave_kind' => 'paid', 'unit' => $am]], false],
            '全休+時間休' => [[['leave_kind' => 'paid', 'unit' => $full], ['leave_kind' => 'special', 'unit' => $hourly]], true],
            '午前半休+午後半休+時間休' => [
                [['leave_kind' => 'paid', 'unit' => $am], ['leave_kind' => 'paid', 'unit' => $pm], ['leave_kind' => 'special', 'unit' => $hourly]],
                true,
            ],
        ];
    }

    /** @param  list<array{leave_kind: string, unit: string}>  $leaves */
    #[DataProvider('fullDayCases')]
    public function test_full_day_is_decided_by_the_active_leaves(array $leaves, bool $expected): void
    {
        $this->assertSame($expected, FullDayLeavePolicy::isFullDay($leaves));
    }

    public function test_a_single_half_day_is_not_a_full_day_whatever_its_kind(): void
    {
        $this->assertFalse(FullDayLeavePolicy::isFullDay([$this->leave(PaidLeaveType::AM_HALF, 'special')]));
        $this->assertFalse(FullDayLeavePolicy::isFullDay([$this->leave(PaidLeaveType::PM_HALF, 'compensatory')]));
    }
}
