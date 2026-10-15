<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\Support\LeaveDayConflictPolicy;
use App\Models\PaidLeaveType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LeaveDayConflictPolicy(同じ勤怠の日の休暇の衝突判定)の検証。DB非依存。
 * 所定労働時間 P=480分(8時間)を基本とし、半休は intdiv(P,2)=240分として数える。
 */
class LeaveDayConflictPolicyTest extends TestCase
{
    private const P = 480;

    private function leave(string $unit, ?int $minutes = null): array
    {
        return ['unit' => $unit, 'minutes' => $minutes];
    }

    /** @return array<string, array{0: list<array{unit: string, minutes: ?int}>, 1: array{unit: string, minutes: ?int}, 2: int, 3: bool}> */
    public static function conflictCases(): array
    {
        $full = PaidLeaveType::FULL;
        $am = PaidLeaveType::AM_HALF;
        $pm = PaidLeaveType::PM_HALF;
        $hourly = PaidLeaveType::HOURLY;

        return [
            // 全休との衝突(既存・新規のどちらが全休でも衝突)
            'existing full and new am_half conflict' => [[['unit' => $full, 'minutes' => null]], ['unit' => $am, 'minutes' => null], self::P, true],
            'existing am_half and new full conflict' => [[['unit' => $am, 'minutes' => null]], ['unit' => $full, 'minutes' => null], self::P, true],
            'existing full and new hourly conflict' => [[['unit' => $full, 'minutes' => null]], ['unit' => $hourly, 'minutes' => 60], self::P, true],
            'existing hourly and new full conflict' => [[['unit' => $hourly, 'minutes' => 60]], ['unit' => $full, 'minutes' => null], self::P, true],
            'two full days conflict' => [[['unit' => $full, 'minutes' => null]], ['unit' => $full, 'minutes' => null], self::P, true],

            // 同じ半休は衝突
            'am_half and am_half conflict' => [[['unit' => $am, 'minutes' => null]], ['unit' => $am, 'minutes' => null], self::P, true],
            'pm_half and pm_half conflict' => [[['unit' => $pm, 'minutes' => null]], ['unit' => $pm, 'minutes' => null], self::P, true],

            // 午前半休と午後半休は両立(種類を問わず)
            'am_half and pm_half are compatible' => [[['unit' => $am, 'minutes' => null]], ['unit' => $pm, 'minutes' => null], self::P, false],
            'pm_half and am_half are compatible' => [[['unit' => $pm, 'minutes' => null]], ['unit' => $am, 'minutes' => null], self::P, false],
            'odd prescribed minutes: am and pm halves still fit' => [[['unit' => $am, 'minutes' => null]], ['unit' => $pm, 'minutes' => null], 481, false],

            // 合計がPちょうどは許容、超過は衝突(時間休は minutes、半休は intdiv(P,2))
            'hourly totals exactly P' => [[['unit' => $hourly, 'minutes' => 240]], ['unit' => $hourly, 'minutes' => 240], self::P, false],
            'hourly totals P plus one minute conflict' => [[['unit' => $hourly, 'minutes' => 240]], ['unit' => $hourly, 'minutes' => 241], self::P, true],
            'half and hourly totals exactly P' => [[['unit' => $am, 'minutes' => null]], ['unit' => $hourly, 'minutes' => 240], self::P, false],
            'half and hourly over P conflict' => [[['unit' => $am, 'minutes' => null]], ['unit' => $hourly, 'minutes' => 241], self::P, true],
            'am, pm and hourly over P conflict' => [
                [['unit' => $am, 'minutes' => null], ['unit' => $pm, 'minutes' => null]],
                ['unit' => $hourly, 'minutes' => 1],
                self::P,
                true,
            ],

            // 0分
            'zero minute hourly leaves do not conflict' => [[['unit' => $hourly, 'minutes' => 0]], ['unit' => $hourly, 'minutes' => 0], self::P, false],
            'zero minute hourly plus P minutes is exactly P' => [[['unit' => $hourly, 'minutes' => 0]], ['unit' => $hourly, 'minutes' => self::P], self::P, false],

            // 既存の休暇が無い
            'new hourly exactly P without existing leaves' => [[], ['unit' => $hourly, 'minutes' => self::P], self::P, false],
            'new hourly over P without existing leaves' => [[], ['unit' => $hourly, 'minutes' => self::P + 1], self::P, true],
            'new half without existing leaves' => [[], ['unit' => $am, 'minutes' => null], self::P, false],
        ];
    }

    /**
     * @param  list<array{unit: string, minutes: ?int}>  $existing
     * @param  array{unit: string, minutes: ?int}  $new
     */
    #[DataProvider('conflictCases')]
    public function test_check(array $existing, array $new, int $prescribedMinutes, bool $expectConflict): void
    {
        $reason = (new LeaveDayConflictPolicy)->check($existing, $new, $prescribedMinutes);

        if ($expectConflict) {
            $this->assertIsString($reason);
            $this->assertNotSame('', $reason);
        } else {
            $this->assertNull($reason);
        }
    }

    public function test_full_day_conflict_reason_mentions_full_day(): void
    {
        $reason = (new LeaveDayConflictPolicy)->check(
            [$this->leave(PaidLeaveType::FULL)],
            $this->leave(PaidLeaveType::AM_HALF),
            self::P,
        );

        $this->assertSame('全休の日には他の休暇を併存できません。', $reason);
    }

    public function test_total_over_prescribed_reason(): void
    {
        $reason = (new LeaveDayConflictPolicy)->check(
            [$this->leave(PaidLeaveType::HOURLY, 240)],
            $this->leave(PaidLeaveType::HOURLY, 241),
            self::P,
        );

        $this->assertSame('休暇の合計が所定労働時間を超えます。', $reason);
    }
}
