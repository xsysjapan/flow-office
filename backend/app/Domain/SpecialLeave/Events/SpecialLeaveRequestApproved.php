<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 特別休暇申請の承認(special_leave.request_approved)。
 * 末尾の項目(申請者・対象日・取得単位・時間数・申請日数・残数を要するか)は、残数側・勤怠側が
 * このイベントだけで処理できるよう集約の状態から記録する(本変更前の保存イベントには無いため既定値nullを持つ)。
 */
class SpecialLeaveRequestApproved extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $approvedByUserId,
        public readonly ?string $userId = null,
        public readonly ?int $specialLeaveTypeId = null,
        public readonly ?string $targetDate = null,
        public readonly ?string $leaveType = null,
        public readonly ?float $hours = null,
        public readonly ?float $requestedDays = null,
        public readonly ?bool $requiresGrant = null,
    ) {}
}
