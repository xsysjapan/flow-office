<?php

namespace App\Domain\PaidLeaveAccount\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveAccountMigrated;
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
 * @phpstan-type GrantState array{grantedOn: string, expiresOn: string, grantedDays: float, grantReason: ?string, source: string, revoked: bool, allocations: array<string, float>, originalGrantedOn?: ?string, originalGrantedDays?: ?float, cutoverMetadata?: ?array}
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

        if ($newGrantedOn > $this->grants[$grantId]['expiresOn']) {
            throw new DomainRuleException('Grant日付は同じGrantの有効期限より後にはできません。');
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

        if ($newExpiresOn < $current['grantedOn']) {
            throw new DomainRuleException('有効期限は同じGrantの付与日より前にはできません。');
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

    /**
     * UC-P005/UC-P006向けの警告済みフラグ記録専用。残高等の不変条件には関与しない
     * (旧`PaidLeaveGrantAggregate::raiseWarning`の置き換え)。
     */
    public function raiseGrantWarning(string $grantId, string $warningType, string $message): self
    {
        $this->recordThat(new PaidLeaveGrantWarningRaised(
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
     * cutover専用の一括初期化。旧システム/旧ドメインProjectionから読み取った「事実」を
     * `PaidLeaveAccountMigrated`イベント1本として記録する。通常の`grant()`が課す
     * 不変条件(直前Grantより後の日付であること・同日禁止・過去挿入禁止)は経由しない
     * ―移行は複数の過去日付Grantを一括でbackfillする必要があるため
     * (spec.md 論点11/§47)。ただし移行は口座ごとに一度きりの操作であるため、
     * 既にGrantが1件でも存在する口座への再実行は拒否する。
     *
     * モデリング上の判断(spec.md「既存データ移行」/依頼書§45「過去の全Usage履歴再現は
     * 必須としない」に基づく): 個々のGrantの不変条件上の「利用可能な上限
     * (=通常の$grantedDays)」は、モードA/B/Cいずれの場合も`remainingDaysAtCutover`
     * (切替時点で実際に残っている日数)そのものとする。モードA/Bで分かっている
     * `originalGrantedOn`/`originalGrantedDays`は、切替前に何日消化済みだったかを
     * 個々のUsage行として再現するためではなく、監査・表示専用の付随情報として
     * Grant状態に保持するに留める(実際のUsage Entityは作らない)。モードC
     * (originalGrantedDaysが未知)ではこの付随情報がnullのまま残り、
     * 「より大きい元の付与日数が本当は存在するが忘れている」という幻の上限は
     * 一切表現しない―`remainingDaysAtCutover`こそが以後の消化・減額判定における
     * 本物の上限になる。
     *
     * 「最新Grant」判定の起算日(`grantedOn`)は、モードA/Bでは`originalGrantedOn`を
     * そのまま使う(実際に付与された日が分かっているため)。モードCで
     * `originalGrantedOn`が不明な場合のみ、順序付けの代替キーとして`cutoverDate`を使う
     * (依頼書は"usage_start_dateはflow-office独自のcutover境界であり法定日ではない"と
     * 明記しており、順序付けの便宜上の基準日として使うことは差し支えない)。
     * 移行後の通常`grant()`呼び出しは、この基準日を追い越す日付でなければ拒否される。
     *
     * @param  array<int, array{grantId: string, originalGrantedOn: ?string, originalGrantedDays: ?float, remainingDaysAtCutover: float, expiresOn: string, source: string, cutoverMetadata: ?array}>  $grants  未整列でよい(内部で`originalGrantedOn ?? cutoverDate`昇順に並べ替える)
     */
    public function migrateGrants(string $cutoverDate, array $grants): self
    {
        if (count($this->grants) > 0) {
            throw new DomainRuleException(
                'このAccountには既にGrantが存在するため、データ移行(migrateGrants)を実行できません(移行は口座ごとに一度きりの操作です)。'
            );
        }

        $seenGrantIds = [];
        foreach ($grants as $g) {
            if (isset($seenGrantIds[$g['grantId']])) {
                throw new DomainRuleException("移行データ内でGrant ID [{$g['grantId']}] が重複しています。");
            }
            $seenGrantIds[$g['grantId']] = true;

            $orderingDate = $g['originalGrantedOn'] ?? $cutoverDate;

            if ($orderingDate > $g['expiresOn']) {
                throw new DomainRuleException("Grant [{$g['grantId']}] の付与日(または切替日)が有効期限より後になっています。");
            }

            if ($g['originalGrantedDays'] !== null && $g['originalGrantedDays'] < $g['remainingDaysAtCutover']) {
                throw new DomainRuleException(
                    "Grant [{$g['grantId']}] の元の付与日数(originalGrantedDays)は切替時点の残日数(remainingDaysAtCutover)を下回れません。"
                );
            }

            if ($g['remainingDaysAtCutover'] < 0) {
                throw new DomainRuleException("Grant [{$g['grantId']}] の切替時点残日数は0以上である必要があります。");
            }
        }

        // grantedOn基準の時系列スタックとして整合させるため、記録前に並べ替える
        // (論点4: Migration専用パスも最終的にgrantedOn昇順になるよう構築する)。
        usort($grants, fn (array $a, array $b) => ($a['originalGrantedOn'] ?? $cutoverDate) <=> ($b['originalGrantedOn'] ?? $cutoverDate));

        $this->recordThat(new PaidLeaveAccountMigrated(cutoverDate: $cutoverDate, grants: $grants));

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

    protected function applyPaidLeaveAccountMigrated(PaidLeaveAccountMigrated $event): void
    {
        foreach ($event->grants as $g) {
            $this->grants[$g['grantId']] = [
                'grantedOn' => $g['originalGrantedOn'] ?? $event->cutoverDate,
                'expiresOn' => $g['expiresOn'],
                // モデリング上の判断(migrateGrantsのdoc参照): 不変条件上の上限は常に
                // remainingDaysAtCutoverそのもの。originalGrantedDaysは付随情報として
                // 別途保持するのみで、grantedDays(=以後の消化・減額判定の基準)には使わない。
                'grantedDays' => $g['remainingDaysAtCutover'],
                'grantReason' => null,
                'source' => $g['source'],
                'revoked' => false,
                'allocations' => [],
                'originalGrantedOn' => $g['originalGrantedOn'],
                'originalGrantedDays' => $g['originalGrantedDays'],
                'cutoverMetadata' => $g['cutoverMetadata'],
            ];
        }
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
