<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 代休申請の差戻し(compensatory_leave.request_returned)。末尾の申請者IDは残数側・勤怠側が
 * このイベントだけで消化記録の取消・勤怠の解除を行えるよう集約の状態から記録する(既定値nullは本変更前の保存イベント用)。
 */
class CompensatoryLeaveRequestReturned extends ShouldBeStored
{
    public function __construct(
        public readonly string $returnedByUserId,
        public readonly string $comment,
        public readonly ?string $userId = null,
    ) {}
}
