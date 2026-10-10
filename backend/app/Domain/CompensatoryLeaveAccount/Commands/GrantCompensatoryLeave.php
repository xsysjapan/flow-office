<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 管理者が休日出勤の対象日(workDate)を指定して代休を手動付与する(作成と同時に確定)。
 * grantIdは付与の集約ID(呼び出し側が生成して渡す。作成後に呼び出し側がそのIDで読み直す)。
 * 休日出勤の実績の有無は代休口座の休日出勤のビュー(計算イベントから作る)で判定する。
 */
class GrantCompensatoryLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $grantId,
        public readonly ?string $expiresOn = null,
        public readonly ?string $grantReason = null,
    ) {}
}
