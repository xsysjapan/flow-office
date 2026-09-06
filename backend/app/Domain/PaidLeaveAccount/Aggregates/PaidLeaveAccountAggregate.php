<?php

namespace App\Domain\PaidLeaveAccount\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantAmountChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantCreated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantDateChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantExpiryChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantRevoked;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocationReleased;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageCancelled;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageConfirmed;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageDesignated;
use App\Domain\PaidLeaveAccount\Support\AllocationPlanner;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * paid_leave_account集約(AggregateId = userId)。社員一人の年休台帳全体
 * (Grant時系列スタック・Usage・Allocation)の不変条件を、replayされた自身の内部状態のみで
 * 保証する(Projection/Eloquentへは一切アクセスしない)。
 * docs/changesets/20260906-paid-leave-domain-redesign/spec.md「仕様確定事項」参照。
 *
 * 不変条件:
 * 1. 新規Grantは非取消の現在最新Grantより`grantedOn`が後(同日・過去挿入不可)。
 * 2. Grant変更・取消は現在最新の非取消Grantのみ許可。
 * 3. Grant減額は`allocated`合計を下回れない。
 * 4. Grant有効期限短縮はAllocation件数0のGrantのみ許可。
 * 5. Grant取消時は当該Grantの全Allocationを解除する(Usage自体は取消しない)。
 * 6. Allocationは`usedOn`基準で有効なGrantのみ対象(処理日・承認日は不使用)。
 * 7. 空き発生時は未充当Usageを`usedOn`昇順に自動Allocationする。既存Allocationは
 *    組み替えない(常に「残りの不足分」だけを追加充当する)。
 *
 * @phpstan-type GrantState array{grantedOn: string, expiresOn: string, grantedDays: float, grantReason: ?string, source: string, revoked: bool, allocations: array<string, float>}
 * @phpstan-type UsageState array{workflowRequestId: ?string, attendanceDayId: ?string, usedOn: string, usedDays: float, confirmed: bool, cancelled: bool, allocations: array<string, float>, order: int}
 */
class PaidLeaveAccountAggregate extends AggregateRoot
{
    /** @var array<string, GrantState> */
    private array $grants = [];

    /** @var array<string, UsageState> */
    private array $usages = [];

    private int $usageSequence = 0;

    public function grant(
        string $grantId,
        string $grantedOn,
        string $expiresOn,
        float $grantedDays,
        ?string $grantReason,
        string $source = 'manual',
    ): self {
        $latest = $this->latestActiveGrant();

        if ($latest !== null && $grantedOn <= $latest['grantedOn']) {
            throw new DomainRuleException(
                '新規Grantは現在の最新Grantより後の日付である必要があります(同日・過去日付は不可)。'
            );
        }

        $this->recordThat(new PaidLeaveGrantCreated(
            grantId: $grantId,
            grantedOn: $grantedOn,
            expiresOn: $expiresOn,
            grantedDays: $grantedDays,
            grantReason: $grantReason,
            source: $source,
        ));

        $this->allocateUnallocatedUsages();

        return $this;
    }

    public function changeGrantAmount(string $grantId, float $newGrantedDays, ?string $reason, string $changedByUserId): self
    {
        $this->assertGrantIsLatestAndActive($grantId);

        $allocatedTotal = array_sum($this->grants[$grantId]['allocations']);

        if ($newGrantedDays < $allocatedTotal) {
            throw new DomainRuleException('既にAllocation済みの日数を下回る減額はできません。');
        }

        $this->recordThat(new PaidLeaveGrantAmountChanged(
            grantId: $grantId,
            newGrantedDays: $newGrantedDays,
            reason: $reason,
            changedByUserId: $changedByUserId,
        ));

        $this->allocateUnallocatedUsages();

        return $this;
    }

    public function changeGrantDate(string $grantId, string $newGrantedOn, ?string $reason, string $changedByUserId): self
    {
        $this->assertGrantIsLatestAndActive($grantId);

        $previous = $this->latestActiveGrantExcluding($grantId);

        if ($previous !== null && $newGrantedOn <= $previous['grantedOn']) {
            throw new DomainRuleException('Grant日付はひとつ前のGrantより後である必要があります。');
        }

        $this->recordThat(new PaidLeaveGrantDateChanged(
            grantId: $grantId,
            newGrantedOn: $newGrantedOn,
            reason: $reason,
            changedByUserId: $changedByUserId,
        ));

        $this->allocateUnallocatedUsages();

        return $this;
    }

    public function changeGrantExpiry(string $grantId, string $newExpiresOn, ?string $reason, string $changedByUserId): self
    {
        $this->assertGrantIsLatestAndActive($grantId);

        $current = $this->grants[$grantId];
        $isShortening = $newExpiresOn < $current['expiresOn'];

        if ($isShortening && count($current['allocations']) > 0) {
            throw new DomainRuleException('Allocation済みのGrantは有効期限を短縮できません。');
        }

        $this->recordThat(new PaidLeaveGrantExpiryChanged(
            grantId: $grantId,
            newExpiresOn: $newExpiresOn,
            reason: $reason,
            changedByUserId: $changedByUserId,
        ));

        $this->allocateUnallocatedUsages();

        return $this;
    }

    public function revokeGrant(string $grantId, string $revokedByUserId, ?string $reason): self
    {
        $this->assertGrantIsLatestAndActive($grantId);

        foreach ($this->grants[$grantId]['allocations'] as $usageId => $days) {
            $this->recordThat(new PaidLeaveUsageAllocationReleased(
                usageId: $usageId,
                grantId: $grantId,
                releasedDays: $days,
            ));
        }

        $this->recordThat(new PaidLeaveGrantRevoked(
            grantId: $grantId,
            revokedByUserId: $revokedByUserId,
            reason: $reason,
        ));

        $this->allocateUnallocatedUsages();

        return $this;
    }

    public function designateUsage(
        string $usageId,
        ?string $workflowRequestId,
        ?string $attendanceDayId,
        string $usedOn,
        float $usedDays,
    ): self {
        if (isset($this->usages[$usageId])) {
            throw new DomainRuleException("Usage [{$usageId}] は既に存在します。");
        }

        $this->recordThat(new PaidLeaveUsageDesignated(
            usageId: $usageId,
            workflowRequestId: $workflowRequestId,
            attendanceDayId: $attendanceDayId,
            usedOn: $usedOn,
            usedDays: $usedDays,
        ));

        return $this;
    }

    public function confirmUsage(string $usageId, ?string $confirmedByUserId): self
    {
        $usage = $this->usages[$usageId] ?? null;

        if ($usage === null) {
            throw new DomainRuleException("Usage [{$usageId}] は存在しません。");
        }

        if ($usage['cancelled']) {
            throw new DomainRuleException('取消済みのUsageは確定できません。');
        }

        if ($usage['confirmed']) {
            throw new DomainRuleException('既に確定済みのUsageです。');
        }

        $this->recordThat(new PaidLeaveUsageConfirmed(usageId: $usageId, confirmedByUserId: $confirmedByUserId));

        $this->allocateUsage($usageId);

        return $this;
    }

    public function cancelUsage(string $usageId, ?string $cancelledByUserId, ?string $reason): self
    {
        $usage = $this->usages[$usageId] ?? null;

        if ($usage === null) {
            throw new DomainRuleException("Usage [{$usageId}] は存在しません。");
        }

        if ($usage['cancelled']) {
            throw new DomainRuleException('既に取消済みのUsageです。');
        }

        foreach ($usage['allocations'] as $grantId => $days) {
            $this->recordThat(new PaidLeaveUsageAllocationReleased(
                usageId: $usageId,
                grantId: $grantId,
                releasedDays: $days,
            ));
        }

        $this->recordThat(new PaidLeaveUsageCancelled(
            usageId: $usageId,
            cancelledByUserId: $cancelledByUserId,
            reason: $reason,
        ));

        $this->allocateUnallocatedUsages();

        return $this;
    }

    /**
     * usedOn基準で有効な最新以前のGrantを対象に、指定Usageの未充当分だけを充当する。
     * 既存のAllocationは組み替えない(残りの不足分にのみ`AllocationPlanner`を適用する)。
     */
    private function allocateUsage(string $usageId): void
    {
        $usage = $this->usages[$usageId] ?? null;

        if ($usage === null || $usage['cancelled'] || ! $usage['confirmed']) {
            return;
        }

        $remaining = $usage['usedDays'] - array_sum($usage['allocations']);

        if ($remaining <= 0) {
            return;
        }

        $plan = (new AllocationPlanner())->plan($usage['usedOn'], $remaining, $this->grantsSnapshot());

        foreach ($plan as $entry) {
            if ($entry['allocatedDays'] <= 0) {
                continue;
            }

            $this->recordThat(new PaidLeaveUsageAllocated(
                usageId: $usageId,
                grantId: $entry['grantId'],
                allocatedDays: $entry['allocatedDays'],
            ));
        }
    }

    /**
     * 空きが発生した契機(Grant追加/増額/expiry延長/Usage取消によるAllocation解除)の
     * 都度呼ばれる。未充当のUsageを`usedOn`昇順(同日は登録順)に安定的に処理し、
     * 既存Allocationを組み替えない。
     */
    private function allocateUnallocatedUsages(): void
    {
        $pending = array_filter(
            $this->usages,
            fn (array $u) => $u['confirmed'] && ! $u['cancelled'] && ($u['usedDays'] - array_sum($u['allocations'])) > 0,
        );

        uasort($pending, function (array $a, array $b) {
            return $a['usedOn'] <=> $b['usedOn'] ?: $a['order'] <=> $b['order'];
        });

        foreach (array_keys($pending) as $usageId) {
            $this->allocateUsage($usageId);
        }
    }

    /**
     * @return array<int, array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocatedTotal: float}>
     */
    private function grantsSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->grants as $grantId => $grant) {
            $snapshot[] = [
                'grantId' => $grantId,
                'grantedOn' => $grant['grantedOn'],
                'expiresOn' => $grant['expiresOn'],
                'grantedDays' => $grant['grantedDays'],
                'revoked' => $grant['revoked'],
                'allocatedTotal' => array_sum($grant['allocations']),
            ];
        }

        return $snapshot;
    }

    /**
     * @return null|array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocations: array<string, float>}
     */
    private function latestActiveGrant(): ?array
    {
        return $this->latestActiveGrantExcluding(null);
    }

    /**
     * @return null|array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocations: array<string, float>}
     */
    private function latestActiveGrantExcluding(?string $excludedGrantId): ?array
    {
        $latest = null;

        foreach ($this->grants as $grantId => $grant) {
            if ($grant['revoked'] || $grantId === $excludedGrantId) {
                continue;
            }

            if ($latest === null || $grant['grantedOn'] > $latest['grantedOn']) {
                $latest = $grant + ['grantId' => $grantId];
            }
        }

        return $latest;
    }

    private function assertGrantIsLatestAndActive(string $grantId): void
    {
        $grant = $this->grants[$grantId] ?? null;

        if ($grant === null || $grant['revoked']) {
            throw new DomainRuleException("Grant [{$grantId}] は存在しないか、既に取り消し済みです。");
        }

        $latest = $this->latestActiveGrant();

        if ($latest === null || $latest['grantId'] !== $grantId) {
            throw new DomainRuleException('最新のGrantのみ変更・取消できます。');
        }
    }

    protected function applyPaidLeaveGrantCreated(PaidLeaveGrantCreated $event): void
    {
        $this->grants[$event->grantId] = [
            'grantedOn' => $event->grantedOn,
            'expiresOn' => $event->expiresOn,
            'grantedDays' => $event->grantedDays,
            'grantReason' => $event->grantReason,
            'source' => $event->source,
            'revoked' => false,
            'allocations' => [],
        ];
    }

    protected function applyPaidLeaveGrantAmountChanged(PaidLeaveGrantAmountChanged $event): void
    {
        $this->grants[$event->grantId]['grantedDays'] = $event->newGrantedDays;
    }

    protected function applyPaidLeaveGrantDateChanged(PaidLeaveGrantDateChanged $event): void
    {
        $this->grants[$event->grantId]['grantedOn'] = $event->newGrantedOn;
    }

    protected function applyPaidLeaveGrantExpiryChanged(PaidLeaveGrantExpiryChanged $event): void
    {
        $this->grants[$event->grantId]['expiresOn'] = $event->newExpiresOn;
    }

    protected function applyPaidLeaveGrantRevoked(PaidLeaveGrantRevoked $event): void
    {
        $this->grants[$event->grantId]['revoked'] = true;
    }

    protected function applyPaidLeaveUsageDesignated(PaidLeaveUsageDesignated $event): void
    {
        $this->usages[$event->usageId] = [
            'workflowRequestId' => $event->workflowRequestId,
            'attendanceDayId' => $event->attendanceDayId,
            'usedOn' => $event->usedOn,
            'usedDays' => $event->usedDays,
            'confirmed' => false,
            'cancelled' => false,
            'allocations' => [],
            'order' => $this->usageSequence++,
        ];
    }

    protected function applyPaidLeaveUsageConfirmed(PaidLeaveUsageConfirmed $event): void
    {
        $this->usages[$event->usageId]['confirmed'] = true;
    }

    protected function applyPaidLeaveUsageCancelled(PaidLeaveUsageCancelled $event): void
    {
        $this->usages[$event->usageId]['cancelled'] = true;
    }

    protected function applyPaidLeaveUsageAllocated(PaidLeaveUsageAllocated $event): void
    {
        $this->usages[$event->usageId]['allocations'][$event->grantId] =
            ($this->usages[$event->usageId]['allocations'][$event->grantId] ?? 0) + $event->allocatedDays;

        $this->grants[$event->grantId]['allocations'][$event->usageId] =
            ($this->grants[$event->grantId]['allocations'][$event->usageId] ?? 0) + $event->allocatedDays;
    }

    protected function applyPaidLeaveUsageAllocationReleased(PaidLeaveUsageAllocationReleased $event): void
    {
        $remainingUsage = ($this->usages[$event->usageId]['allocations'][$event->grantId] ?? 0) - $event->releasedDays;

        if ($remainingUsage <= 0) {
            unset($this->usages[$event->usageId]['allocations'][$event->grantId]);
        } else {
            $this->usages[$event->usageId]['allocations'][$event->grantId] = $remainingUsage;
        }

        $remainingGrant = ($this->grants[$event->grantId]['allocations'][$event->usageId] ?? 0) - $event->releasedDays;

        if ($remainingGrant <= 0) {
            unset($this->grants[$event->grantId]['allocations'][$event->usageId]);
        } else {
            $this->grants[$event->grantId]['allocations'][$event->usageId] = $remainingGrant;
        }
    }
}
