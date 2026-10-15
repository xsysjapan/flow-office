<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 未使用の確定済み代休の取消を申請する。承認不要設定のときは申請を経ずに付与を取り消す
 * (取消は口座集約の`cancelGrant`で行う。承認が必要なときは取消申請の行を作る)。
 * requestedByUserIdは申請者(付与の利用者本人のみ)。
 */
class RequestCompensatoryLeaveGrantCancellation implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $requestedByUserId,
        public readonly ?string $reason = null,
    ) {}
}
