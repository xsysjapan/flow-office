<?php

namespace App\Domain\SpecialLeaveAccount\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountGrantRegistered;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountGrantRevoked;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageCancelled;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageConfirmed;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageDesignated;
use App\Domain\SpecialLeaveAccount\Support\SpecialLeaveAllocationPlanner;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 利用者単位の特別休暇口座集約。付与(種類ごと)・消化記録(申請ごと)・充当の残数を一括で持ち、
 * 不変条件を自身の内部状態(replayされたイベント)だけで保証する(Eloquent/Projectionへは
 * アクセスしない)。現行ルールの移植元は各メソッドのdocを参照する。
 *
 * 集約IDは利用者IDから決定的に派生させる(streamIdFor)。有給の口座集約(集約ID=userId)と衝突しない。
 *
 * 状態(replay):
 * - grants: grantId => [specialLeaveTypeId, grantedOn, expiresOn, grantedDays, revoked, allocations(usageId => days)]
 * - usages: usageId => [requestId, specialLeaveTypeId, usedOn, usageType, usedDays, usedMinutes,
 *           status(designated|confirmed|cancelled), allocations(grantId => days), unallocatedDays(float)]
 * - requestIndex: requestId => 最後に作成されたusageId(再申請で新しい消化記録に差し替わる)
 *
 * 不変条件:
 * 1. 付与の残数(grantedDays - 充当合計)は0未満にならない(充当は残数の範囲内のみ)。
 * 2. 同じ申請の有効な消化記録は1件だけ(取消後の再申請は新しいusageIdで作成可能)。
 * 3. 確定は1回だけ・取消済みは確定不可、取消は1回だけ。取消で充当を全て解除する。
 * 4. 消化済み(充当合計>0)の付与は取り消せない。
 * 5. 残数が不足しても承認はブロックしない(ユーザー決定)。充当できた分だけ充当し、残りは
 *    未充当日数(unallocatedDays)として確定時に記録する。
 */
class SpecialLeaveAccountAggregate extends AggregateRoot
{
    private const STATUS_DESIGNATED = 'designated';

    private const STATUS_CONFIRMED = 'confirmed';

    private const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, array{specialLeaveTypeId: int, grantedOn: string, expiresOn: ?string, grantedDays: float, revoked: bool, allocations: array<string, float>}> */
    private array $grants = [];

    /** @var array<string, array{requestId: string, specialLeaveTypeId: int, usedOn: string, usageType: string, usedDays: float, usedMinutes: ?int, status: string, allocations: array<string, float>, unallocatedDays: float}> */
    private array $usages = [];

    /** @var array<string, string> */
    private array $requestIndex = [];

    /**
     * 同じ申請IDに対して、これまでに消化記録を指定した回数(取消済みを含む)を返す。
     * 再提出の回数に当たり、消化記録のusageIdの採番(LeaveUsageId)に使う(論点18 2.1)。
     */
    public function designationCountForRequest(string $requestId): int
    {
        $count = 0;
        foreach ($this->usages as $usage) {
            if ($usage['requestId'] === $requestId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 利用者IDから特別休暇口座の集約IDを決定的に派生させる。
     * 方式は既存の`UserManagementStreamId`(UUIDv5)に従う。
     */
    public static function streamIdFor(string $userId): string
    {
        return UserManagementStreamId::for('special_leave_account', $userId);
    }

    /**
     * 付与を登録する。旧来の特別休暇付与と同じく、日数・日付の妥当性は
     * 検証しない(現行にもルールが無いため。報告の確認事項を参照)。
     */
    public function registerGrant(
        string $grantId,
        int $specialLeaveTypeId,
        string $grantedOn,
        ?string $expiresOn,
        float $grantedDays,
        ?string $grantReason,
        ?string $userId = null,
    ): self {
        if (isset($this->grants[$grantId])) {
            throw new DomainRuleException("Grant [{$grantId}] は既に存在します。");
        }

        $this->recordThat(new SpecialLeaveAccountGrantRegistered(
            grantId: $grantId,
            specialLeaveTypeId: $specialLeaveTypeId,
            grantedOn: $grantedOn,
            expiresOn: $expiresOn,
            grantedDays: $grantedDays,
            grantReason: $grantReason,
            userId: $userId,
        ));

        return $this;
    }

    /**
     * 未消化の付与を取り消す。移植元: `RevokeSpecialLeaveGrantHandler`
     * (取消済みは拒否、消化済み日数が0より大きければ拒否)。
     */
    public function revokeGrant(string $grantId, string $revokedByUserId, ?string $reason): self
    {
        $grant = $this->grants[$grantId] ?? null;

        if ($grant === null) {
            throw new DomainRuleException("Grant [{$grantId}] は存在しません。");
        }

        if ($grant['revoked']) {
            throw new DomainRuleException('この特別休暇付与は既に取り消し済みです。');
        }

        if ($this->usedDaysOf($grantId) > 0) {
            throw new DomainRuleException('既に消化された分は取り消せません。');
        }

        $this->recordThat(new SpecialLeaveAccountGrantRevoked(
            grantId: $grantId,
            revokedByUserId: $revokedByUserId,
            reason: $reason,
        ));

        return $this;
    }

    /**
     * 休暇申請に対応する消化記録を作成する(申請時。充当は行わない)。
     * 同じ申請に有効な消化記録(designated/confirmed)が既にあれば拒否する。
     */
    public function designateUsage(
        string $usageId,
        string $requestId,
        int $specialLeaveTypeId,
        string $usedOn,
        string $usageType,
        float $usedDays,
        ?int $usedMinutes,
        ?string $userId = null,
    ): self {
        if (isset($this->usages[$usageId])) {
            throw new DomainRuleException("Usage [{$usageId}] は既に存在します。");
        }

        $currentUsageId = $this->requestIndex[$requestId] ?? null;

        if ($currentUsageId !== null && $this->activeUsage($currentUsageId)) {
            throw new DomainRuleException("申請 [{$requestId}] の消化記録は既に存在します。");
        }

        $this->recordThat(new SpecialLeaveAccountUsageDesignated(
            usageId: $usageId,
            requestId: $requestId,
            specialLeaveTypeId: $specialLeaveTypeId,
            usedOn: $usedOn,
            usageType: $usageType,
            usedDays: $usedDays,
            usedMinutes: $usedMinutes,
            userId: $userId,
        ));

        return $this;
    }

    /**
     * 消化記録を確定する(承認時)。移植元: `ApproveSpecialLeaveRequestHandler::planConsumption`。
     *
     * - `requiresGrant=false`(残数を要しない種別): 充当なしで確定する(未充当日数は0)。
     * - `requiresGrant=true`: 利用日時点で有効な同じ種類の付与へ現行の順序で充当する。
     *   残数が不足しても例外にせず、充当できた分だけを充当して確定する(ユーザー決定)。
     *   残りは未充当日数(`unallocatedFor`で参照)として記録する。付与が無い場合は充当0・未充当=全量。
     *
     * @throws DomainRuleException 存在しない・取消済み・確定済み
     */
    public function confirmUsage(string $usageId, bool $requiresGrant): self
    {
        $usage = $this->usageOrFail($usageId);

        if ($usage['status'] === self::STATUS_CANCELLED) {
            throw new DomainRuleException('取消済みの消化記録は確定できません。');
        }

        if ($usage['status'] === self::STATUS_CONFIRMED) {
            throw new DomainRuleException('既に確定済みの消化記録です。');
        }

        $allocations = [];
        $unallocatedDays = 0.0;

        if ($requiresGrant) {
            $allocations = (new SpecialLeaveAllocationPlanner)->plan(
                usedOn: $usage['usedOn'],
                usedDays: $usage['usedDays'],
                grants: $this->grantsSnapshot($usage['specialLeaveTypeId']),
            );

            $allocatedDays = (float) array_sum(array_column($allocations, 'allocatedDays'));

            $unallocatedDays = max(0.0, $usage['usedDays'] - $allocatedDays);
        }

        $this->recordThat(new SpecialLeaveAccountUsageConfirmed(
            usageId: $usageId,
            allocations: $allocations,
            unallocatedDays: $unallocatedDays,
        ));

        return $this;
    }

    /**
     * 消化記録を取り消す(差戻し・取消)。確定済みなら充当を全て解除して残数を戻す。
     * 移植元: `CancelSpecialLeaveRequestHandler`(承認済みの取消のみ消化を取り消す)。
     */
    public function cancelUsage(string $usageId, ?string $reason): self
    {
        $usage = $this->usageOrFail($usageId);

        if ($usage['status'] === self::STATUS_CANCELLED) {
            throw new DomainRuleException('既に取消済みの消化記録です。');
        }

        $releasedAllocations = [];

        foreach ($usage['allocations'] as $grantId => $days) {
            $releasedAllocations[] = ['grantId' => (string) $grantId, 'releasedDays' => $days];
        }

        $this->recordThat(new SpecialLeaveAccountUsageCancelled(
            usageId: $usageId,
            releasedAllocations: $releasedAllocations,
            reason: $reason,
        ));

        return $this;
    }

    /**
     * 申請に対応する最後の消化記録のusageIdを返す(取消済みも含む)。無ければnull。
     */
    public function usageIdForRequest(string $requestId): ?string
    {
        return $this->requestIndex[$requestId] ?? null;
    }

    /**
     * 申請に有効な消化記録(申請中・確定)があるか。Reactor経由の二重作成を防ぐ冪等判定に使う。
     */
    public function hasActiveUsageForRequest(string $requestId): bool
    {
        $usageId = $this->requestIndex[$requestId] ?? null;

        return $usageId !== null && $this->activeUsage($usageId);
    }

    public function hasUsage(string $usageId): bool
    {
        return isset($this->usages[$usageId]);
    }

    /**
     * 消化記録の状態(designated|confirmed|cancelled)。存在しなければnull。
     */
    public function usageStatus(string $usageId): ?string
    {
        return $this->usages[$usageId]['status'] ?? null;
    }

    /**
     * 確定時に充当できなかった未充当日数。確定していない・取消済み・存在しない消化記録は0.0。
     */
    public function unallocatedFor(string $usageId): float
    {
        return $this->usages[$usageId]['unallocatedDays'] ?? 0.0;
    }

    /**
     * 指定日時点で利用可能な付与(取消済みを除き、失効していない)の残数の合計。
     * 失効日当日は利用可能。充当計画と同じ判定を使う。
     */
    public function remaining(int $specialLeaveTypeId, string $today): float
    {
        $total = 0.0;

        foreach ($this->grants as $grantId => $grant) {
            if ($grant['specialLeaveTypeId'] !== $specialLeaveTypeId) {
                continue;
            }

            if ($grant['revoked'] || ($grant['expiresOn'] !== null && $grant['expiresOn'] < $today)) {
                continue;
            }

            $total += $grant['grantedDays'] - $this->usedDaysOf((string) $grantId);
        }

        return $total;
    }

    protected function applySpecialLeaveAccountGrantRegistered(SpecialLeaveAccountGrantRegistered $event): void
    {
        $this->grants[$event->grantId] = [
            'specialLeaveTypeId' => $event->specialLeaveTypeId,
            'grantedOn' => $event->grantedOn,
            'expiresOn' => $event->expiresOn,
            'grantedDays' => $event->grantedDays,
            'revoked' => false,
            'allocations' => [],
        ];
    }

    protected function applySpecialLeaveAccountGrantRevoked(SpecialLeaveAccountGrantRevoked $event): void
    {
        $this->grants[$event->grantId]['revoked'] = true;
    }

    protected function applySpecialLeaveAccountUsageDesignated(SpecialLeaveAccountUsageDesignated $event): void
    {
        $this->usages[$event->usageId] = [
            'requestId' => $event->requestId,
            'specialLeaveTypeId' => $event->specialLeaveTypeId,
            'usedOn' => $event->usedOn,
            'usageType' => $event->usageType,
            'usedDays' => $event->usedDays,
            'usedMinutes' => $event->usedMinutes,
            'status' => self::STATUS_DESIGNATED,
            'allocations' => [],
            'unallocatedDays' => 0.0,
        ];

        $this->requestIndex[$event->requestId] = $event->usageId;
    }

    protected function applySpecialLeaveAccountUsageConfirmed(SpecialLeaveAccountUsageConfirmed $event): void
    {
        $this->usages[$event->usageId]['status'] = self::STATUS_CONFIRMED;
        $this->usages[$event->usageId]['unallocatedDays'] = $event->unallocatedDays;

        foreach ($event->allocations as $allocation) {
            $this->addAllocation($event->usageId, $allocation['grantId'], $allocation['allocatedDays']);
        }
    }

    protected function applySpecialLeaveAccountUsageCancelled(SpecialLeaveAccountUsageCancelled $event): void
    {
        $this->usages[$event->usageId]['status'] = self::STATUS_CANCELLED;
        $this->usages[$event->usageId]['unallocatedDays'] = 0.0;

        foreach ($event->releasedAllocations as $released) {
            unset($this->usages[$event->usageId]['allocations'][$released['grantId']]);
            unset($this->grants[$released['grantId']]['allocations'][$event->usageId]);
        }
    }

    private function usageOrFail(string $usageId): array
    {
        $usage = $this->usages[$usageId] ?? null;

        if ($usage === null) {
            throw new DomainRuleException("Usage [{$usageId}] は存在しません。");
        }

        return $usage;
    }

    private function activeUsage(string $usageId): bool
    {
        return in_array($this->usages[$usageId]['status'] ?? null, [self::STATUS_DESIGNATED, self::STATUS_CONFIRMED], true);
    }

    private function addAllocation(string $usageId, string $grantId, float $days): void
    {
        $this->usages[$usageId]['allocations'][$grantId] = ($this->usages[$usageId]['allocations'][$grantId] ?? 0.0) + $days;
        $this->grants[$grantId]['allocations'][$usageId] = ($this->grants[$grantId]['allocations'][$usageId] ?? 0.0) + $days;
    }

    private function usedDaysOf(string $grantId): float
    {
        return (float) array_sum($this->grants[$grantId]['allocations']);
    }

    /**
     * 充当計画へ渡す、同じ種類・取消済みを含む付与の一覧(登録順)。取消済みは計画側で除外される。
     *
     * @return array<int, array{grantId: string, expiresOn: ?string, grantedDays: float, allocatedTotal: float, revoked: bool}>
     */
    private function grantsSnapshot(int $specialLeaveTypeId): array
    {
        $snapshot = [];

        foreach ($this->grants as $grantId => $grant) {
            if ($grant['specialLeaveTypeId'] !== $specialLeaveTypeId) {
                continue;
            }

            $snapshot[] = [
                'grantId' => (string) $grantId,
                'expiresOn' => $grant['expiresOn'],
                'grantedDays' => $grant['grantedDays'],
                'allocatedTotal' => $this->usedDaysOf((string) $grantId),
                'revoked' => $grant['revoked'],
            ];
        }

        return $snapshot;
    }
}
