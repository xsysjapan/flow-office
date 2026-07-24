<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P002: 有給を付与する。
 * MVPでは付与要件(継続勤務期間・出勤率)の自動判定バッチは対象外(docs/21-mvp-scope.md)。
 * 人事担当者が付与日数を決定して手動実行する土台として提供する。
 */
class GrantPaidLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantedOn,
        public readonly string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
    ) {}
}
