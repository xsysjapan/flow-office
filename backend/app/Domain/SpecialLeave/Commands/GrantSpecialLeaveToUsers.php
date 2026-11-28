<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇を複数の利用者へ手動付与する(全部か無しか。1人でも付与できなければ何も記録しない)。
 * 付与IDは`requestId`と利用者IDから決まり、付与済みの利用者は何もせず成功とする(再送で二重にならない)。
 * グループの指定は入口で`userIds`に展開してから渡す(`userLabels`は失敗の表示名: 利用者ID=>表示名)。
 */
class GrantSpecialLeaveToUsers implements Command
{
    /**
     * @param  list<string>  $userIds
     * @param  array<string, string>  $userLabels
     */
    public function __construct(
        public readonly string $requestId,
        public readonly array $userIds,
        public readonly int $specialLeaveTypeId,
        public readonly string $grantedOn,
        public readonly ?string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
        public readonly array $userLabels = [],
    ) {}
}
