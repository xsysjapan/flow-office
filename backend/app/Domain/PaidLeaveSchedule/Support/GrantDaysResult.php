<?php

namespace App\Domain\PaidLeaveSchedule\Support;

/**
 * `ScheduleCandidateGenerator::resolveGrantDays()`の戻り値。「0.0日」と
 * 「確定不可(ルールのステップ欠落・NeedsReview区分・法定Policy未整備等)」を
 * 呼び出し側が区別できるようにするための値オブジェクト(spec.md 移植対象Feature2)。
 * `isDeterminate === false`の場合、生成されるScheduleエントリはScheduledではなく
 * NeedsReview状態で作成する。
 */
final class GrantDaysResult
{
    private function __construct(
        public readonly float $days,
        public readonly bool $isDeterminate,
    ) {}

    public static function determinate(float $days): self
    {
        return new self($days, true);
    }

    public static function indeterminate(): self
    {
        return new self(0.0, false);
    }
}
