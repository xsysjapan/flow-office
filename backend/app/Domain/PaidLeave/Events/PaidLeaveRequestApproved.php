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

class PaidLeaveRequestApproved extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $approvedByUserId,
    ) {}
}
