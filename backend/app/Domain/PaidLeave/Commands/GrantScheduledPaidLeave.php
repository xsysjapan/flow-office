<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P002: 有給を自動付与する(バッチ)。$asOfを指定しない場合は実行日を基準日とする
 * (テスト時に基準日を固定するために指定できる)。
 */
class GrantScheduledPaidLeave implements Command
{
    public function __construct(
        public readonly ?string $asOf = null,
    ) {}
}
