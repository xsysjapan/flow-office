<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D002: 端末アプリがペアリングコードをSanctumトークンへ交換する。既存のSSOトークン交換
 * フロー(docs/06-usecases-auth.md UC-001、AuthController::token)と同じ「一度きりのコードを
 * トークンに交換する」パターンを流用する。
 */
class ExchangeDevicePairingCode implements Command
{
    public function __construct(
        public readonly int $deviceId,
        public readonly string $pairingCode,
    ) {}
}
