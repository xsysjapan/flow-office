<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 経費精算を取り消す。
 *
 * `viaReactor=true`は、ワークフローの取消を受けて取り消すReactorが使う。申請者本人のチェックは行わず、
 * 既に取消済み・取消不可の状態なら何もしない(冪等)。
 */
class CancelExpenseClaim implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $cancelledByUserId,
        public readonly string $reason,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
