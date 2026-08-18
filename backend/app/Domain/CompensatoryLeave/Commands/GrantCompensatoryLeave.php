<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 管理者が、休日出勤の対象日(workDate)を指定して代休を手動付与する。付与日数は
 * その日の勤怠実績の実労働時間から自動導出フロー(SyncCompensatoryLeaveGrant)と
 * 同じルールで算出する(GrantCompensatoryLeaveHandler参照)。承認不要のため
 * 直接status=confirmedで作成する。
 */
class GrantCompensatoryLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly ?string $expiresOn,
        public readonly ?string $grantReason,
    ) {}
}
