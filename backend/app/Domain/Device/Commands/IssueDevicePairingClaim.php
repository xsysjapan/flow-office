<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D002: 管理者の認証済みセッションで、共有端末が自身をペアリング完了させるための
 * 一時トークン(claim token)を発行する。この一時トークンは`device:claim-pairing`
 * abilityのみを持つ短命なSanctumトークンであり、それ自体では打刻等の業務APIを
 * 一切呼び出せない。管理者トークンをそのまま端末へ渡すのではなく、この一時トークンを
 * QRコード等で渡すことで、なりすまし対策(管理者本来の権限は端末に渡さない)と
 * 有効期限・失効の制御(Sanctumの`expires_at`、`tokens()->delete()`)を両立する。
 */
class IssueDevicePairingClaim implements Command
{
    public function __construct(
        public readonly string $deviceId,
        public readonly int $issuedByUserId,
    ) {}
}
