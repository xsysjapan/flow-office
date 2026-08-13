<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-C014: カレンダー年度を定期バッチ(またはUC-C011「今すぐ生成する」)で生成する。
 * `companyCalendarId`を省略すると全ての会社カレンダー本体が対象になる。
 */
class GenerateCompanyCalendarYears implements Command
{
    public function __construct(
        public readonly ?string $companyCalendarId = null,
        public readonly bool $isBatch = true,
    ) {}
}
