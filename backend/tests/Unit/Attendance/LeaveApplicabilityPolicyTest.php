<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\Support\LeaveApplicabilityPolicy;
use App\Models\AttendanceDayLeave;
use App\Models\PaidLeaveType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LeaveApplicabilityPolicy(勤務予定日・時間休の時間数と所定労働時間の整合の判定)の検証。DB非依存。
 */
class LeaveApplicabilityPolicyTest extends TestCase
{
    private const P = 480;

    /** @return array<string, array{0: string, 1: string, 2: ?int, 3: bool, 4: int, 5: ?string}> */
    public static function cases(): array
    {
        $paid = AttendanceDayLeave::KIND_PAID;
        $special = AttendanceDayLeave::KIND_SPECIAL;
        $comp = AttendanceDayLeave::KIND_COMPENSATORY;
        $full = PaidLeaveType::FULL;
        $hourly = PaidLeaveType::HOURLY;

        return [
            'paid full on a working day is allowed' => [$paid, $full, null, true, self::P, null],
            'paid full on a non-working day is rejected' => [$paid, $full, null, false, self::P, '勤務予定日ではないため有給を申請できません。'],
            'special full on a non-working day is rejected' => [$special, $full, null, false, self::P, '勤務予定日ではないため特別休暇を申請できません。'],
            'compensatory full on a non-working day is rejected' => [$comp, $full, null, false, self::P, '勤務予定日ではないため代休を申請できません。'],
            'paid half on a non-working day is rejected' => [$paid, PaidLeaveType::AM_HALF, null, false, self::P, '勤務予定日ではないため有給を申請できません。'],

            'paid hourly 60 minutes is allowed' => [$paid, $hourly, 60, true, self::P, null],
            'paid hourly just under a day is allowed (rounds to 0.9)' => [$paid, $hourly, 432, true, self::P, null],
            'paid hourly equal to the prescribed minutes is rejected' => [$paid, $hourly, 480, true, self::P, '時間休として妥当な取得時間を指定してください。'],
            'paid hourly over the prescribed minutes is rejected' => [$paid, $hourly, 600, true, self::P, '時間休として妥当な取得時間を指定してください。'],
            'paid hourly that rounds to zero days is rejected' => [$paid, $hourly, 10, true, self::P, '時間休として妥当な取得時間を指定してください。'],
            'paid hourly without a prescribed time is rejected' => [$paid, $hourly, 60, true, 0, '働き方が特定できないため時間休を申請できません。'],

            'special hourly 60 minutes is allowed' => [$special, $hourly, 60, true, self::P, null],
            'special hourly over a day is rejected' => [$special, $hourly, 600, true, self::P, '時間単位として妥当な取得時間を指定してください。'],
            'special hourly without a prescribed time is rejected' => [$special, $hourly, 60, true, 0, '働き方が特定できないため時間単位の特別休暇を申請できません。'],

            'compensatory hourly is not checked against the prescribed time' => [$comp, $hourly, 600, true, self::P, null],
            'a non-working day is checked before the hourly range' => [$paid, $hourly, 600, false, self::P, '勤務予定日ではないため有給を申請できません。'],
        ];
    }

    #[DataProvider('cases')]
    public function test_check(string $kind, string $unit, ?int $minutes, bool $isWorkingDay, int $prescribed, ?string $expected): void
    {
        $this->assertSame($expected, (new LeaveApplicabilityPolicy)->check($kind, $unit, $minutes, $isWorkingDay, $prescribed));
    }
}
