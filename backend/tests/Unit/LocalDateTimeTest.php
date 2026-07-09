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
}
