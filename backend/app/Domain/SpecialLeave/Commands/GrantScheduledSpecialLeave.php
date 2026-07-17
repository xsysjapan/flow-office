<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇種別ごとの自動付与ルールに基づき、対象者へ特別休暇を自動付与する(バッチ)。
 * $asOfを指定しない場合は実行日を基準日とする(テスト時に基準日を固定するために指定できる)。
 */
class GrantScheduledSpecialLeave implements Command
{
    public function __construct(
        public readonly ?string $asOf = null,
    ) {}
}
