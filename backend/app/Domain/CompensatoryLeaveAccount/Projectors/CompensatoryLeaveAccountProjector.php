<?php

namespace App\Domain\CompensatoryLeaveAccount\Projectors;

use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantCancelled;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantConfirmed;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantManuallyGranted;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantRemoved;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantSynced;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountMigrated;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageCancelled;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageConfirmed;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageDesignated;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\CompensatoryLeaveUsage;
use App\Models\CompensatoryLeaveUsageAllocation;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 代休口座の集約(compensatory_leave_account.*)のイベントから、既存の付与テーブル(compensatory_leave_grants)・
 * 消化記録テーブル(compensatory_leave_usages)と、充当の表(compensatory_leave_usage_allocations)を元のIDで作成・更新する。
 * SpecialLeaveAccountProjectorと同じ形。
 *
 * - 付与・消化記録の行は口座のイベントの内容だけで作る(旧系統のイベントに依存しない)。旧系統の既存Projector
 *   (CompensatoryLeaveGrantProjector)は移行前の再生用に残す。
 * - 残数(付与のused_days・remaining_days)は充当の表の合計から毎回計算し直す(加減算しない)。同じイベントを
 *   再適用しても、リビルドしても同じ結果になる(冪等)。
 * - 行の存在を前提とする更新は、行が無ければ何もしない(findOrFailを使わない。順序非依存)。
 * - 消化記録の行は取消時に削除する(現時点で有効な消化の一覧。旧Projectorと同じ)。履歴はstored_eventsに残る。
 */
class CompensatoryLeaveAccountProjector extends Projector
{
    public function onCompensatoryLeaveAccountGrantSynced(CompensatoryLeaveAccountGrantSynced $event): void
    {
        $this->assertUserId($event->userId, $event->grantId);

        CompensatoryLeaveGrant::query()->updateOrCreate(
            ['id' => $event->grantId],
            $this->grantAttributes(
                userId: $event->userId,
                source: 'attendance',
                workDate: $event->sourceWorkDate,
                grantedDays: $event->grantedDays,
                grantedMinutes: $event->grantedMinutes,
                status: CompensatoryLeaveGrantStatus::DRAFT,
                confirmedAt: null,
                expiresOn: null,
                grantReason: null,
            ),
        );

        $this->recalculateGrant($event->grantId);
    }

    public function onCompensatoryLeaveAccountGrantManuallyGranted(CompensatoryLeaveAccountGrantManuallyGranted $event): void
    {
        $this->assertUserId($event->userId, $event->grantId);

        CompensatoryLeaveGrant::query()->updateOrCreate(
            ['id' => $event->grantId],
            $this->grantAttributes(
                userId: $event->userId,
                source: 'manual',
                workDate: $event->sourceWorkDate,
                grantedDays: $event->grantedDays,
                grantedMinutes: $event->grantedMinutes,
                status: CompensatoryLeaveGrantStatus::CONFIRMED,
                confirmedAt: $event->createdAt(),
                expiresOn: $event->expiresOn,
                grantReason: $event->grantReason,
            ),
        );

        $this->recalculateGrant($event->grantId);
    }

    public function onCompensatoryLeaveAccountGrantConfirmed(CompensatoryLeaveAccountGrantConfirmed $event): void
    {
        CompensatoryLeaveGrant::query()->whereKey($event->grantId)->update([
            'status' => CompensatoryLeaveGrantStatus::CONFIRMED,
            'confirmed_at' => $event->confirmedAt,
            'expires_on' => $event->expiresOn,
        ]);
    }

    public function onCompensatoryLeaveAccountGrantCancelled(CompensatoryLeaveAccountGrantCancelled $event): void
    {
        CompensatoryLeaveGrant::query()->whereKey($event->grantId)->update([
            'status' => CompensatoryLeaveGrantStatus::CANCELLED,
            'remaining_days' => 0,
            'remaining_minutes' => 0,
        ]);
    }

    /** 月次未提出(下書き)の同期付与の削除(休日出勤でなくなった・勤怠日の削除)。 */
    public function onCompensatoryLeaveAccountGrantRemoved(CompensatoryLeaveAccountGrantRemoved $event): void
    {
        CompensatoryLeaveGrant::query()->whereKey($event->grantId)->delete();
    }

    public function onCompensatoryLeaveAccountUsageDesignated(CompensatoryLeaveAccountUsageDesignated $event): void
    {
        $this->assertUserId($event->userId, $event->usageId);

        // 同じ申請に旧系統(usage_idを持たない)の消化記録が残っていれば置き換える。
        $this->deleteLegacyUsages($event->requestId);

        CompensatoryLeaveUsage::query()->updateOrCreate(
            ['usage_id' => $event->usageId],
            $this->usageAttributes(
                userId: $event->userId,
                requestId: $event->requestId,
                usedOn: $event->usedOn,
                usageType: $event->usageType,
                usedDays: $event->usedDays,
                usedMinutes: $event->usedMinutes,
                isConfirmed: false,
                unallocatedDays: 0.0,
                unallocatedMinutes: 0,
            ),
        );
    }

    public function onCompensatoryLeaveAccountUsageConfirmed(CompensatoryLeaveAccountUsageConfirmed $event): void
    {
        $usage = CompensatoryLeaveUsage::query()->where('usage_id', $event->usageId)->first();

        if ($usage === null) {
            return;
        }

        $grantIds = $this->replaceAllocations($event->usageId, $event->allocations);

        $usage->update([
            'is_confirmed' => true,
            'unallocated_days' => $event->unallocatedDays,
            'unallocated_minutes' => $event->unallocatedMinutes,
            'compensatory_leave_grant_id' => count($grantIds) === 1 ? $grantIds[0] : null,
        ]);

        $this->recalculateGrants($grantIds);
    }

    public function onCompensatoryLeaveAccountUsageCancelled(CompensatoryLeaveAccountUsageCancelled $event): void
    {
        $grantIds = CompensatoryLeaveUsageAllocation::query()
            ->where('usage_id', $event->usageId)
            ->pluck('grant_id')
            ->map(fn ($grantId) => (string) $grantId)
            ->all();

        CompensatoryLeaveUsageAllocation::query()->where('usage_id', $event->usageId)->delete();
        CompensatoryLeaveUsage::query()->where('usage_id', $event->usageId)->delete();

        $this->recalculateGrants($grantIds);
    }

    /**
     * 移行(compensatory_leave_account.migrated)。本変更前の付与・消化記録の現在の状態を元のIDで書き込む。
     * 移行より前の旧系統のイベントで作った行は、同じ付与IDなら上書き、同じ申請の旧系統の消化記録は置き換える。
     */
    public function onCompensatoryLeaveAccountMigrated(CompensatoryLeaveAccountMigrated $event): void
    {
        $this->assertUserId($event->userId, 'migrated');

        $grantIds = [];

        foreach ($event->grants as $grant) {
            CompensatoryLeaveGrant::query()->updateOrCreate(
                ['id' => $grant['grantId']],
                $this->grantAttributes(
                    userId: $event->userId,
                    source: $grant['source'] === 'manual' ? 'manual' : 'attendance',
                    workDate: $grant['sourceWorkDate'],
                    grantedDays: $grant['grantedDays'],
                    grantedMinutes: $grant['grantedMinutes'],
                    status: $grant['status'],
                    confirmedAt: $grant['status'] === CompensatoryLeaveGrantStatus::CONFIRMED ? $event->createdAt() : null,
                    expiresOn: $grant['expiresOn'],
                    grantReason: null,
                ),
            );

            $grantIds[] = (string) $grant['grantId'];
        }

        foreach ($event->usages as $usage) {
            $this->deleteLegacyUsages($usage['requestId']);

            CompensatoryLeaveUsage::query()->updateOrCreate(
                ['usage_id' => $usage['usageId']],
                $this->usageAttributes(
                    userId: $event->userId,
                    requestId: $usage['requestId'],
                    usedOn: $usage['usedOn'],
                    usageType: $usage['usageType'],
                    usedDays: $usage['usedDays'],
                    usedMinutes: $usage['usedMinutes'],
                    isConfirmed: $usage['status'] === 'confirmed',
                    unallocatedDays: 0.0,
                    unallocatedMinutes: 0,
                ),
            );

            $usageGrantIds = $this->replaceAllocations($usage['usageId'], $usage['allocations']);
            CompensatoryLeaveUsage::query()->where('usage_id', $usage['usageId'])->update([
                'compensatory_leave_grant_id' => count($usageGrantIds) === 1 ? $usageGrantIds[0] : null,
            ]);

            $grantIds = array_merge($grantIds, $usageGrantIds);
        }

        $this->recalculateGrants(array_values(array_unique($grantIds)));
    }

    /**
     * 付与の属性。残数の列(NOT NULL)は付与時点の値で埋め、直後の再計算で充当の合計に合わせる。
     *
     * @return array<string, mixed>
     */
    private function grantAttributes(
        string $userId,
        string $source,
        string $workDate,
        float $grantedDays,
        ?int $grantedMinutes,
        string $status,
        mixed $confirmedAt,
        ?string $expiresOn,
        ?string $grantReason,
    ): array {
        return [
            'user_id' => $userId,
            'source' => $source,
            'attendance_day_id' => null,
            'work_date' => $workDate,
            'granted_days' => $grantedDays,
            'granted_minutes' => $grantedMinutes,
            'used_days' => 0,
            'used_minutes' => $grantedMinutes !== null ? 0 : null,
            'remaining_days' => $grantedDays,
            'remaining_minutes' => $grantedMinutes,
            'status' => $status,
            'confirmed_at' => $confirmedAt,
            'expires_on' => $expiresOn,
            'grant_reason' => $grantReason,
        ];
    }

    /**
     * 消化記録の行の属性。勤怠日・申請テーブルへの外部キーは持たせない。
     *
     * @return array<string, mixed>
     */
    private function usageAttributes(
        string $userId,
        string $requestId,
        string $usedOn,
        string $usageType,
        float $usedDays,
        ?int $usedMinutes,
        bool $isConfirmed,
        float $unallocatedDays,
        int $unallocatedMinutes,
    ): array {
        return [
            'user_id' => $userId,
            'attendance_day_id' => null,
            'compensatory_leave_grant_id' => null,
            'compensatory_leave_request_id' => $requestId,
            'used_on' => $usedOn,
            'used_days' => $usedDays,
            'used_minutes' => $usedMinutes,
            'usage_type' => $usageType,
            'is_confirmed' => $isConfirmed,
            'unallocated_days' => $unallocatedDays,
            'unallocated_minutes' => $unallocatedMinutes,
        ];
    }

    /**
     * 消化記録の充当を置き換える(付与IDの一覧を返す。残数の再計算に使う)。
     *
     * @param  array<int, array{grantId: string, allocatedDays: float, allocatedMinutes: int}>  $allocations
     * @return string[]
     */
    private function replaceAllocations(string $usageId, array $allocations): array
    {
        $grantIds = CompensatoryLeaveUsageAllocation::query()
            ->where('usage_id', $usageId)
            ->pluck('grant_id')
            ->map(fn ($grantId) => (string) $grantId)
            ->all();

        CompensatoryLeaveUsageAllocation::query()->where('usage_id', $usageId)->delete();

        foreach ($allocations as $allocation) {
            CompensatoryLeaveUsageAllocation::query()->create([
                'usage_id' => $usageId,
                'grant_id' => $allocation['grantId'],
                'allocated_days' => $allocation['allocatedDays'],
                'allocated_minutes' => $allocation['allocatedMinutes'],
            ]);

            $grantIds[] = (string) $allocation['grantId'];
        }

        return array_values(array_unique($grantIds));
    }

    /** 同じ申請の、充当の表に紐づかない(旧系統の)消化記録の行を削除する。 */
    private function deleteLegacyUsages(string $requestId): void
    {
        CompensatoryLeaveUsage::query()
            ->where('compensatory_leave_request_id', $requestId)
            ->whereNull('usage_id')
            ->delete();
    }

    /**
     * @param  string[]  $grantIds
     */
    private function recalculateGrants(array $grantIds): void
    {
        foreach ($grantIds as $grantId) {
            $this->recalculateGrant($grantId);
        }
    }

    /**
     * 付与の消化済み・残数を充当の表の合計から計算し直す(表示・残数判定用の非正規化キャッシュ。正は充当の表とイベント)。
     * 付与の行が無い、または取消済みの付与は何もしない(取消時に残数を0にする既存の扱い)。
     */
    private function recalculateGrant(string $grantId): void
    {
        $grant = CompensatoryLeaveGrant::query()->find($grantId);

        if ($grant === null || $grant->status === CompensatoryLeaveGrantStatus::CANCELLED) {
            return;
        }

        $usedDays = (float) CompensatoryLeaveUsageAllocation::query()->where('grant_id', $grantId)->sum('allocated_days');
        $usedMinutes = (int) CompensatoryLeaveUsageAllocation::query()->where('grant_id', $grantId)->sum('allocated_minutes');

        $grant->update([
            'used_days' => $usedDays,
            'remaining_days' => (float) $grant->granted_days - $usedDays,
            'used_minutes' => $grant->granted_minutes !== null ? $usedMinutes : null,
            'remaining_minutes' => $grant->granted_minutes !== null ? (int) $grant->granted_minutes - $usedMinutes : null,
        ]);
    }

    private function assertUserId(?string $userId, string $label): void
    {
        if ($userId === null) {
            throw new \LogicException("代休口座のイベントに利用者IDがありません: {$label}");
        }
    }
}
