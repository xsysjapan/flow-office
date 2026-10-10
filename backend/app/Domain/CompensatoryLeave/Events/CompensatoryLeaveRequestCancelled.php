<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 代休申請の取消(compensatory_leave.request_cancelled)。末尾の項目は残数側・勤怠側が
 * このイベントだけで消化記録の取消・勤怠の解除を行えるよう集約の状態から記録する(既定値nullは本変更前の保存イベント用)。
 */
class CompensatoryLeaveRequestCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $cancelledByUserId,
        public readonly ?string $userId = null,
        public readonly ?string $reason = null,
    ) {}
}
