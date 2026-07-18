<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D002: 共有端末のペアリングコードを発行する。ペアリングコード自体は一度きりの
 * 短命なセキュリティ材料であり、業務上の事実ではないためstored_eventsには記録しない
 * (system_settings/request_types と同じ「マスタ的な設定」の扱いに準ずる、
 * docs/23-usecases-devices.md参照)。
 */
class IssueDevicePairingCode implements Command
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $issuedByUserId,
    ) {}
}
