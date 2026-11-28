<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇を付与する(人事担当者が対象者・種別・日数・失効日を指定して都度実行)。
 * 有給と異なり法定の時効がないため、$expiresOnはnullable(null=失効しない)。
 */
class GrantSpecialLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly int $specialLeaveTypeId,
        public readonly string $grantedOn,
        public readonly ?string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
        /** 付与IDの指定(省略時は新規UUID)。手動付与は`request_id`と利用者IDから決めたIDを渡す。 */
        public readonly ?string $grantId = null,
        /** true: 同じ付与IDの付与が既にあれば何もせず(通知もせず)既存の付与を返す(再送を冪等にする)。 */
        public readonly bool $idempotent = false,
    ) {}
}
