<?php

namespace App\Domain\Attendance\Services;

use Illuminate\Support\Carbon;

/**
 * @see AttendancePunchReconciler::classify()
 */
final class PunchReconciliationResult
{
    /**
     * @param  array{clock_in: Carbon, clock_out: Carbon, breaks: array<int, array{start: Carbon, end: Carbon}>, utc_offset_minutes: int}|null  $reconciled
     */
    private function __construct(
        public readonly PunchLogOutcome $outcome,
        public readonly ?array $reconciled = null,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @param  array{clock_in: Carbon, clock_out: Carbon, breaks: array<int, array{start: Carbon, end: Carbon}>, utc_offset_minutes: int}  $reconciled
     */
    public static function complete(array $reconciled): self
    {
        return new self(PunchLogOutcome::Complete, reconciled: $reconciled);
    }

    public static function inProgress(): self
    {
        return new self(PunchLogOutcome::InProgress);
    }

    public static function contradictory(string $reason): self
    {
        return new self(PunchLogOutcome::Contradictory, reason: $reason);
    }
}
