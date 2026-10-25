<?php

namespace App\Domain\PaidLeaveAccount\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantAmountChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantCreated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantDateChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantExpiryChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantRevoked;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantWarningRaised;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocationReleased;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageCancelled;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageConfirmed;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageDesignated;
use App\Domain\PaidLeaveAccount\Support\AllocationPlanner;
use App\Domain\PaidLeaveAccount\Support\GrantTimeAllocationPlanner;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * paid_leave_account集約(AggregateId = `streamIdFor(userId)`。社員IDから派生させた別のストリーム)。社員一人の年休台帳全体
 * (Grant時系列スタック・Usage・Allocation)の不変条件を、replayされた自身の内部状態のみで
 * 保証する(Projection/Eloquentへは一切アクセスしない)。
 * docs/changesets/20260906-paid-leave-domain-redesign/spec.md「仕様確定事項」参照。
 *
 * 不変条件:
 * 1. 新規Grantは非取消の現在最新Grantより`grantedOn`が前でない(同日は可・過去挿入不可)。既にあるGrant IDは拒否。
 * 2. Grant変更・取消は現在最新の非取消Grantのみ許可(最新=付与日が最も遅く、その中で記録の順`order`が最大)。
 * 3. Grant減額は`allocated`合計を下回れない。
 * 4. Grant有効期限短縮はAllocation件数0のGrantのみ許可。
 * 5. Grant取消時は当該Grantの全Allocationを解除する(Usage自体は取消しない)。
 * 6. Allocationは`usedOn`基準で有効なGrantのみ対象(処理日・承認日は不使用)。
 * 7'. 付与時の未充当の充当は`allocateUnallocatedOnGrant`(Reactorから呼ぶ。論点19 決定10-1)。
 * 7. 空き発生時は未充当Usageを`usedOn`昇順に自動Allocationする。既存Allocationは
 *    組み替えない(常に「残りの不足分」だけを追加充当する)。
 *    残数が不足していても承認(`confirmUsage`)は拒否せず、充当できた分だけ充当し残りは未充当のまま残す
 *    (docs/changesets/20261009-keep-leave-work-type-on-edit 論点17の改訂)。
 *
 * @phpstan-type GrantState array{grantedOn: string, expiresOn: string, grantedDays: float, grantReason: ?string, source: string, revoked: bool, allocations: array<string, float>, order: int}
 * @phpstan-type UsageState array{workflowRequestId: ?string, attendanceDayId: ?string, paidLeaveRequestId: ?string, usedOn: string, usedDays: float, confirmed: bool, cancelled: bool, allocations: array<string, float>, order: int, confirmOrder?: int}
 */
class PaidLeaveAccountAggregate extends AggregateRoot
{
    /** @var array<string, GrantState> */
    private array $grants = [];

    /** @var array<string, UsageState> */
    private array $usages = [];

    private int $usageSequence = 0;

    private int $grantSequence = 0;

    private int $confirmSequence = 0;

    /**
     * 有給申請ID→最後に指定されたusageId(取消済みを含む)。`PaidLeaveUsageDesignated`の
     * `paidLeaveRequestId`から構築する。
     *
     * @var array<string, string>
     */
    private array $usageIdByPaidLeaveRequestId = [];

    /** 集約が対象とする社員(forUserで束縛する。イベントの社員IDに使う)。 */
    private ?string $userId = null;

    /**
     * 社員IDから有給口座の集約IDを決定的に派生させる。方式は既存の`UserManagementStreamId`(UUIDv5)に従う。
     * 社員の集約(集約ID=社員ID)や他の口座とストリームを共有しない。
     */
    public static function streamIdFor(string $userId): string
    {
        return UserManagementStreamId::for('paid_leave_account', $userId);
    }

    /**
     * この集約が対象とする社員を束縛する(集約IDは`streamIdFor`で決める)。全ての操作の前に呼ぶ。
     */
    public function forUser(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    private function requireUserId(): string
    {
        if ($this->userId === null) {
            throw new \LogicException('有給口座の操作の前に社員を束縛してください(forUser)。');
        }

        return $this->userId;
    }

    public function grant(
        string $grantId,
        string $grantedOn,
        string $expiresOn,
        float $grantedDays,
        ?string $grantReason,
        string $source = 'manual',
    ): self {
        if (isset($this->grants[$grantId])) {
            throw new DomainRuleException("この付与はすでに記録されています(Grant [{$grantId}])。");
        }

        $latest = $this->latestActiveGrant();

        if ($latest !== null && $grantedOn < $latest['grantedOn']) {
            throw new DomainRuleException(
                '新規Grantは現在の最新Grant以降の日付である必要があります(同日は可・過去日付は不可)。'
            );
        }

        $this->recordThat(new PaidLeaveGrantCreated(
            userId: $this->requireUserId(),
            grantId: $grantId,
            grantedOn: $grantedOn,
            expiresOn: $expiresOn,
            grantedDays: $grantedDays,
            grantReason: $grantReason,
            source: $source,
        ));

        // 付与時の未充当の充当は、付与の記録を受けるReactorが allocateUnallocatedOnGrant() で行う(論点19 決定10-1)。

        return $this;
    }

    /**
     * 複数の付与を、1回のpersistで記録する(まとめて付与する2件を、片方だけ記録されないようにするため)。
     * 各付与は`grant`と同じ検証を順に受ける(同じ日の付与は可)。1件でも失敗したら、何も記録しない
     * (例外はpersist前に出る)。
     *
     * @param  array<int, array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, grantReason: ?string, source?: string}>  $grants
     */
    public function grantMany(array $grants): self
    {
        foreach ($grants as $grant) {
            $this->grant(
                grantId: $grant['grantId'],
                grantedOn: $grant['grantedOn'],
                expiresOn: $grant['expiresOn'],
                grantedDays: $grant['grantedDays'],
                grantReason: $grant['grantReason'],
                source: $grant['source'] ?? 'manual',
            );
        }

        return $this;
    }

    /**
     * 付与時の未充当の充当(論点19 決定10-1)。確定済み・未取消で充当が使用日数に足りない消化記録へ、確定の古い順に、
     * 指定した付与の残りの範囲で充当する。付与の有効期間と消化記録の対象日の関係は問わない。
     * 既に充当済みの消化記録・取消済みの消化記録は対象外。対象が無ければ何も記録しない(冪等)。
     */
    public function allocateUnallocatedOnGrant(string $grantId): self
    {
        $grant = $this->grants[$grantId] ?? null;

        if ($grant === null || $grant['revoked']) {
            return $this;
        }

        $pending = [];
        foreach ($this->usages as $usageId => $usage) {
            if (! $usage['confirmed'] || $usage['cancelled']) {
                continue;
            }
            $pending[] = [
                'usageId' => (string) $usageId,
                'remainingDays' => $usage['usedDays'] - array_sum($usage['allocations']),
                'confirmOrder' => $usage['confirmOrder'] ?? 0,
            ];
        }

        $plan = (new GrantTimeAllocationPlanner)->plan(
            $grant['grantedDays'] - array_sum($grant['allocations']),
            $pending,
        );

        foreach ($plan as $entry) {
            $this->recordThat(new PaidLeaveUsageAllocated(
                userId: $this->requireUserId(),
                usageId: $entry['usageId'],
                grantId: $grantId,
                allocatedDays: $entry['allocatedDays'],
            ));
        }

        return $this;
    }

    public function changeGrantAmount(string $grantId, float $newGrantedDays, ?string $reason, string $changedByUserId): self
    {
        $this->assertGrantIsLatestAndActive($grantId);

        // 日数が変わらない操作はイベントを記録しない。
        if (abs($newGrantedDays - $this->grants[$grantId]['grantedDays']) < 0.00001) {
            return $this;
        }

        $allocatedTotal = array_sum($this->grants[$grantId]['allocations']);

        if ($newGrantedDays < $allocatedTotal) {
            throw new DomainRuleException('既にAllocation済みの日数を下回る減額はできません。');
        }

        $this->recordThat(new PaidLeaveGrantAmountChanged(
            userId: $this->requireUserId(),
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

        if ($previous !== null && $newGrantedOn < $previous['grantedOn']) {
            throw new DomainRuleException('Grant日付はひとつ前のGrant以降である必要があります(同日は可)。');
        }

        if ($newGrantedOn > $this->grants[$grantId]['expiresOn']) {
            throw new DomainRuleException('Grant日付は同じGrantの有効期限より後にはできません。');
        }

        $this->recordThat(new PaidLeaveGrantDateChanged(
            userId: $this->requireUserId(),
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

        if ($newExpiresOn < $current['grantedOn']) {
            throw new DomainRuleException('有効期限は同じGrantの付与日より前にはできません。');
        }

        $this->recordThat(new PaidLeaveGrantExpiryChanged(
            userId: $this->requireUserId(),
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
                userId: $this->requireUserId(),
                usageId: $usageId,
                grantId: $grantId,
                releasedDays: $days,
            ));
        }

        $this->recordThat(new PaidLeaveGrantRevoked(
            userId: $this->requireUserId(),
            grantId: $grantId,
            revokedByUserId: $revokedByUserId,
            reason: $reason,
        ));

        $this->allocateUnallocatedUsages();

        return $this;
    }

    /**
     * UC-P005/UC-P006向けの警告済みフラグ記録専用。残高等の不変条件には関与しない
     * (旧`PaidLeaveGrantAggregate::raiseWarning`の置き換え)。
     */
    public function raiseGrantWarning(string $grantId, string $warningType, string $message): self
    {
        $this->recordThat(new PaidLeaveGrantWarningRaised(
            userId: $this->requireUserId(),
            grantId: $grantId,
            warningType: $warningType,
            message: $message,
        ));

        return $this;
    }

    public function designateUsage(
        string $usageId,
        ?string $workflowRequestId,
        ?string $attendanceDayId,
        string $usedOn,
        float $usedDays,
        string $usageType,
        ?string $paidLeaveRequestId = null,
        ?string $approverUserId = null,
        ?string $reason = null,
        ?string $requestGroupId = null,
        ?float $hours = null,
    ): self {
        if (isset($this->usages[$usageId])) {
            throw new DomainRuleException("Usage [{$usageId}] は既に存在します。");
        }

        // usageType/paidLeaveRequestId等は集約の不変条件には使わない(有給固有の申請
        // パラメータの表示投影用にイベントへ乗せて運ぶだけ。
        // PaidLeaveUsageAllocationProjector::createPaidLeaveRequestIfNeeded参照)。
        $this->recordThat(new PaidLeaveUsageDesignated(
            userId: $this->requireUserId(),
            usageId: $usageId,
            workflowRequestId: $workflowRequestId,
            attendanceDayId: $attendanceDayId,
            usedOn: $usedOn,
            usedDays: $usedDays,
            usageType: $usageType,
            paidLeaveRequestId: $paidLeaveRequestId,
            approverUserId: $approverUserId,
            reason: $reason,
            requestGroupId: $requestGroupId,
            hours: $hours,
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

        $this->recordThat(new PaidLeaveUsageConfirmed(userId: $this->requireUserId(), usageId: $usageId, confirmedByUserId: $confirmedByUserId));

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
                userId: $this->requireUserId(),
                usageId: $usageId,
                grantId: $grantId,
                releasedDays: $days,
            ));
        }

        $this->recordThat(new PaidLeaveUsageCancelled(
            userId: $this->requireUserId(),
            usageId: $usageId,
            cancelledByUserId: $cancelledByUserId,
            reason: $reason,
        ));

        $this->allocateUnallocatedUsages();

        return $this;
    }

    /**
     * 有給申請IDに対して最後に指定されたusageIdを返す(取消済みのusageも含む)。未指定ならnull。
     */
    public function usageIdForRequest(string $paidLeaveRequestId): ?string
    {
        return $this->usageIdByPaidLeaveRequestId[$paidLeaveRequestId] ?? null;
    }

    /**
     * usageの状態を返す。存在しなければnull。
     *
     * @return null|'designated'|'confirmed'|'cancelled'
     */
    public function usageStatus(string $usageId): ?string
    {
        $usage = $this->usages[$usageId] ?? null;

        if ($usage === null) {
            return null;
        }

        if ($usage['cancelled']) {
            return 'cancelled';
        }

        return $usage['confirmed'] ? 'confirmed' : 'designated';
    }

    /**
     * 同じ有給申請IDに対して、これまでに消化記録を指定した回数(取消済みを含む)を返す。
     * 再提出の回数に当たり、消化記録のusageIdの採番(LeaveUsageId)に使う(論点18 2.1)。
     */
    public function designationCountForRequest(string $paidLeaveRequestId): int
    {
        $count = 0;
        foreach ($this->usages as $usage) {
            if ($usage['paidLeaveRequestId'] === $paidLeaveRequestId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 同じ有給申請IDに、取消されていない消化記録が1件でもあるかを返す。
     */
    public function hasActiveUsageForRequest(string $paidLeaveRequestId): bool
    {
        foreach ($this->usages as $usage) {
            if ($usage['paidLeaveRequestId'] === $paidLeaveRequestId && ! $usage['cancelled']) {
                return true;
            }
        }

        return false;
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

        $plan = (new AllocationPlanner)->plan($usage['usedOn'], $remaining, $this->grantsSnapshot());

        foreach ($plan as $entry) {
            if ($entry['allocatedDays'] <= 0) {
                continue;
            }

            $this->recordThat(new PaidLeaveUsageAllocated(
                userId: $this->requireUserId(),
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
     * @return array<int, array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocatedTotal: float, order: int}>
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
                'order' => $grant['order'],
            ];
        }

        return $snapshot;
    }

    /**
     * @return null|array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocations: array<string, float>, order: int}
     */
    private function latestActiveGrant(): ?array
    {
        return $this->latestActiveGrantExcluding(null);
    }

    /**
     * 最新の非取消付与のID(付与日が最も遅く、同じなら記録の順が最後)。無ければnull。
     * 変更・取消できる付与の判定(`assertGrantIsLatestAndActive`)と同じ判定で、画面の「最新」の表示に使う。
     */
    public function latestActiveGrantId(): ?string
    {
        return $this->latestActiveGrant()['grantId'] ?? null;
    }

    /**
     * @return null|array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocations: array<string, float>, order: int}
     */
    private function latestActiveGrantExcluding(?string $excludedGrantId): ?array
    {
        $latest = null;

        foreach ($this->grants as $grantId => $grant) {
            if ($grant['revoked'] || $grantId === $excludedGrantId) {
                continue;
            }

            // 最新 = 付与日が最も遅く、その中で記録の順(order)が最も大きい付与。
            if ($latest === null
                || $grant['grantedOn'] > $latest['grantedOn']
                || ($grant['grantedOn'] === $latest['grantedOn'] && $grant['order'] > $latest['order'])) {
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
            'order' => $this->grantSequence++,
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
            'paidLeaveRequestId' => $event->paidLeaveRequestId,
            'usedOn' => $event->usedOn,
            'usedDays' => $event->usedDays,
            'confirmed' => false,
            'cancelled' => false,
            'allocations' => [],
            'order' => $this->usageSequence++,
        ];

        if ($event->paidLeaveRequestId !== null) {
            $this->usageIdByPaidLeaveRequestId[$event->paidLeaveRequestId] = $event->usageId;
        }
    }

    protected function applyPaidLeaveUsageConfirmed(PaidLeaveUsageConfirmed $event): void
    {
        $this->usages[$event->usageId]['confirmed'] = true;
        $this->usages[$event->usageId]['confirmOrder'] = $this->confirmSequence++;
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
