<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 代休申請の承認(compensatory_leave.request_approved)。末尾の申請者IDは、残数側(消化記録の確定)と
 * 勤怠側(再計算)がこのイベントだけで処理できるよう集約の状態から記録する(本変更前の保存イベントには無いため既定値nullを持つ)。
 */
class CompensatoryLeaveRequestApproved extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $approvedByUserId,
        public readonly ?string $userId = null,
    ) {}
}
