<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 代休を複数の利用者へ手動付与する(全部か無しか。1人でも付与できなければ何も記録しない)。個人の指定だけ(グループの指定は無い)。
 * 付与IDは`requestId`と利用者IDから決まり、付与済みの利用者は何もせず成功とする(再送で二重にならない)。
 * `userLabels`は失敗の表示名(利用者ID=>表示名)。
 */
class GrantCompensatoryLeaveToUsers implements Command
{
    /**
     * @param  list<string>  $userIds
     * @param  array<string, string>  $userLabels
     */
    public function __construct(
        public readonly string $requestId,
        public readonly array $userIds,
        public readonly string $workDate,
        public readonly ?string $expiresOn,
        public readonly ?string $grantReason,
        public readonly array $userLabels = [],
    ) {}
}
