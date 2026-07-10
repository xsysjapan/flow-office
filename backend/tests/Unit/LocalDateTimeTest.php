<?php

namespace Tests\Unit;

use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class LocalDateTimeTest extends TestCase
{
    public function test_parse_converts_an_offset_string_to_the_target_timezone_wall_clock(): void
    {
        $result = LocalDateTime::parse('2026-07-09T21:00:00+09:00', 'Asia/Tokyo');

        $this->assertSame('2026-07-09 21:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_parse_normalizes_a_different_offset_to_the_target_timezone_wall_clock(): void
    {
        // UTC 12:00 は Asia/Tokyo (+09:00) では21:00。
        $result = LocalDateTime::parse('2026-07-09T12:00:00+00:00', 'Asia/Tokyo');

        $this->assertSame('2026-07-09 21:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_to_iso8601_attaches_the_target_timezone_offset_to_a_naive_wall_clock(): void
    {
        $naive = Carbon::createFromFormat('Y-m-d H:i:s', '2026-07-09 21:00:00', 'UTC');

        $this->assertSame('2026-07-09T21:00:00+09:00', LocalDateTime::toIso8601($naive, 'Asia/Tokyo'));
    }

    public function test_to_iso8601_returns_null_for_null_input(): void
    {
        $this->assertNull(LocalDateTime::toIso8601(null, 'Asia/Tokyo'));
    }

    public function test_round_trip_preserves_the_wall_clock_digits(): void
    {
        $stored = LocalDateTime::parse('2026-07-09T21:00:00+09:00', 'Asia/Tokyo');
        $naiveAsStoredByEloquent = Carbon::createFromFormat('Y-m-d H:i:s', $stored->format('Y-m-d H:i:s'), 'UTC');

        $this->assertSame('2026-07-09T21:00:00+09:00', LocalDateTime::toIso8601($naiveAsStoredByEloquent, 'Asia/Tokyo'));
    }

    public function test_split_offset_keeps_the_wall_clock_digits_as_given_without_converting(): void
    {
        [$naive, $offsetMinutes] = LocalDateTime::splitOffset('2026-07-09T22:00:00-05:00');

        $this->assertSame('2026-07-09 22:00:00', $naive->format('Y-m-d H:i:s'));
        $this->assertSame(-300, $offsetMinutes);
    }

    public function test_split_offset_accepts_z_as_a_zero_offset(): void
    {
        [$naive, $offsetMinutes] = LocalDateTime::splitOffset('2026-07-09T13:00:00Z');

        $this->assertSame('2026-07-09 13:00:00', $naive->format('Y-m-d H:i:s'));
        $this->assertSame(0, $offsetMinutes);
    }

    public function test_split_offset_rejects_a_string_without_an_offset(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LocalDateTime::splitOffset('2026-07-09T13:00:00');
    }

    public function test_format_with_offset_minutes_builds_an_iso8601_string(): void
    {
        $naive = Carbon::createFromFormat('Y-m-d H:i:s', '2026-07-09 22:00:00');

        $this->assertSame('2026-07-09T22:00:00-05:00', LocalDateTime::formatWithOffsetMinutes($naive, -300));
        $this->assertSame('2026-07-09T22:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($naive, 540));
    }

    public function test_format_with_offset_minutes_returns_null_for_null_input(): void
    {
        $this->assertNull(LocalDateTime::formatWithOffsetMinutes(null, 540));
    }

    public function test_split_offset_and_format_with_offset_minutes_round_trip(): void
    {
        [$naive, $offsetMinutes] = LocalDateTime::splitOffset('2026-07-09T22:00:00-05:00');

        $this->assertSame('2026-07-09T22:00:00-05:00', LocalDateTime::formatWithOffsetMinutes($naive, $offsetMinutes));
    }
}
