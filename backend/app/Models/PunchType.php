<?php

namespace App\Models;

/**
 * attendance_punches.punch_type。
 */
final class PunchType
{
    public const CLOCK_IN = 'clock_in';

    public const BREAK_START = 'break_start';

    public const BREAK_END = 'break_end';

    public const CLOCK_OUT = 'clock_out';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [self::CLOCK_IN, self::BREAK_START, self::BREAK_END, self::CLOCK_OUT];
    }
}
