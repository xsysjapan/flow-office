<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * self_service/backoffice/approval すべてこの1つのCommandを使う。差は呼び出し側
 * (Controller等、本フェーズの対象外)の権限・前提条件チェックのみ(spec 論点1)。
 */
class LendAsset implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $borrowerUserId,
        public readonly string $lentByUserId,
        public readonly ?string $expectedReturnAt = null,
        public readonly ?string $loanRequestId = null,
    ) {}
}
