<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Phase 5(cutover、docs/changesets/20260906-paid-leave-domain-redesign/spec.md)により
 * 旧App\Domain\PaidLeaveドメイン(Aggregate/Handler/Projector)は削除された。この
 * イベントクラスは新規に発行されることはなく、過去のstored_eventsを
 * event_class_map経由で解決するためだけに残置している(監査・event-sourcing:replay
 * ツールが古い行を解決できるようにするため。クラス自体を削除すると
 * enforce_event_class_map=trueの下で解決不能になる)。
 */

/**
 * 申請時点(承認前)で対象日を有給休暇として設定したことを記録する。paid_leave_request
 * 集約が記録するイベントであり、この時点ではどのpaid_leave_grantから消化するかは
 * まだ決まっていない(承認時のPaidLeaveUsed参照)。
 */
class PaidLeaveUsageDesignated extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $attendanceDayId,
        public readonly string $usedOn,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
        public readonly string $usageType,
    ) {}
}
