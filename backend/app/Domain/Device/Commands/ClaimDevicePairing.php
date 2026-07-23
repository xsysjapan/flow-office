<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D002: 端末アプリが一時トークン(claim token)を使って、本来の業務用Sanctumトークンを
 * 受け取る。呼び出し元(`$request->user()`)が既にこの一時トークンで認証されている
 * 前提のため、コマンド自体はどのdeviceかを受け取るだけでよい。
 */
class ClaimDevicePairing implements Command
{
    public function __construct(
        public readonly string $deviceId,
    ) {}
}
