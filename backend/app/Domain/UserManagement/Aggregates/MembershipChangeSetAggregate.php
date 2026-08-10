<?php

namespace App\Domain\UserManagement\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Events\MembershipChangeSetApplied;
use App\Domain\UserManagement\Events\MembershipChangeSetCancelled;
use App\Domain\UserManagement\Events\MembershipChangeSetCreated;
use App\Domain\UserManagement\Events\MembershipChangeSetFailed;
use App\Domain\UserManagement\Events\MembershipChangeSetScheduled;
use App\Domain\UserManagement\Events\MembershipChangeSetUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class MembershipChangeSetAggregate extends AggregateRoot
{
    private ?string $status = null;

    public function create(string $userId, string $effectiveAt, string $source, array $items, ?string $note, string $actor): self
    {
        if ($this->status !== null) {
            throw new DomainRuleException('変更セットは既に存在します。');
        } $this->recordThat(new MembershipChangeSetCreated($userId, $effectiveAt, $source, $items, $note, $actor));

        return $this;
    }

    public function schedule(string $userId, string $effectiveAt, string $source, array $items, ?string $note, string $actor): self
    {
        if ($this->status === null) {
            $this->create($userId, $effectiveAt, $source, $items, $note, $actor);
        } if ($this->status !== 'draft') {
            throw new DomainRuleException('DRAFTだけを予約できます。');
        } $this->recordThat(new MembershipChangeSetScheduled($userId, $effectiveAt, $source, $items, $note, $actor));

        return $this;
    }

    public function update(string $userId, string $effectiveAt, string $source, array $items, ?string $note, string $actor): self
    {
        if (! in_array($this->status, ['draft', 'scheduled'], true)) {
            throw new DomainRuleException('適用前だけ更新できます。');
        } $this->recordThat(new MembershipChangeSetUpdated($userId, $effectiveAt, $source, $items, $note, $actor));

        return $this;
    }

    public function markApplied(string $userId, array $items, string $actor): self
    {
        if ($this->status !== 'scheduled') {
            throw new DomainRuleException('SCHEDULEDだけを適用できます。');
        } $this->recordThat(new MembershipChangeSetApplied($userId, $items, $actor));

        return $this;
    }

    public function cancel(string $actor): self
    {
        if (! in_array($this->status, ['draft', 'scheduled'], true)) {
            throw new DomainRuleException('適用前だけ取り消せます。');
        } $this->recordThat(new MembershipChangeSetCancelled($actor));

        return $this;
    }

    public function markFailed(string $reason, string $actor): self
    {
        if ($this->status !== 'scheduled') {
            throw new DomainRuleException('SCHEDULEDだけを失敗にできます。');
        } $this->recordThat(new MembershipChangeSetFailed($reason, $actor));

        return $this;
    }

    public function applyMembershipChangeSetCreated(MembershipChangeSetCreated $event): void
    {
        $this->status = 'draft';
    }

    public function applyMembershipChangeSetScheduled(MembershipChangeSetScheduled $event): void
    {
        $this->status = 'scheduled';
    }

    public function applyMembershipChangeSetUpdated(MembershipChangeSetUpdated $event): void {}

    public function applyMembershipChangeSetApplied(MembershipChangeSetApplied $event): void
    {
        $this->status = 'applied';
    }

    public function applyMembershipChangeSetFailed(MembershipChangeSetFailed $event): void
    {
        $this->status = 'failed';
    }

    public function applyMembershipChangeSetCancelled(MembershipChangeSetCancelled $event): void
    {
        $this->status = 'cancelled';
    }
}
