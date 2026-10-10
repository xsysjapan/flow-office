<?php

namespace App\Domain\SpecialLeaveAccount\Projectors;

use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountGrantRegistered;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountGrantRevoked;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountMigrated;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageCancelled;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageConfirmed;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageDesignated;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveGrantStatus;
use App\Models\SpecialLeaveUsage;
use App\Models\SpecialLeaveUsageAllocation;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 特別休暇口座の集約(special_leave_account.*)のイベントから、既存の付与テーブル(special_leave_grants)・
 * 消化記録テーブル(special_leave_usages)と、充当の表(special_leave_usage_allocations)を元のIDで作成・更新する。
 *
 * - 付与・消化記録の行は口座のイベントの内容だけで作る(旧系統のイベントに依存しない)。旧系統の既存Projector
 *   (SpecialLeaveGrantProjector・SpecialLeaveUsageProjector)は移行前の再生用に残す。
 * - 残数(付与のused_days・remaining_days)は充当の表の合計から毎回計算し直す(加減算しない)。同じイベントを
 *   再適用しても、リビルドしても同じ結果になる(冪等)。
 * - 行の存在を前提とする更新は、行が無ければ何もしない(findOrFailを使わない。順序非依存)。
 * - 消化記録の行は取消時に削除する(特別休暇の消化記録テーブルは「現時点で有効な消化」の一覧。旧Projectorと同じ)。
 *   履歴はstored_eventsに残る。
 */
class SpecialLeaveAccountProjector extends Projector
{
    public function onSpecialLeaveAccountGrantRegistered(SpecialLeaveAccountGrantRegistered $event): void
    {
        $this->assertUserId($event->userId, $event->grantId);

        SpecialLeaveGrant::query()->updateOrCreate(
            ['id' => $event->grantId],
            [
                'user_id' => $event->userId,
                'special_leave_type_id' => $event->specialLeaveTypeId,
                'granted_on' => $event->grantedOn,
                'expires_on' => $event->expiresOn,
                'granted_days' => $event->grantedDays,
                // 残数の列(NOT NULL)は付与時点の値で埋め、直後の再計算で充当の合計に合わせる(旧Projectorと同じ初期値)。
                'used_days' => 0,
                'remaining_days' => $event->grantedDays,
                'grant_reason' => $event->grantReason,
                'status' => SpecialLeaveGrantStatus::ACTIVE,
            ],
        );

        $this->recalculateGrant($event->grantId);
    }

    public function onSpecialLeaveAccountGrantRevoked(SpecialLeaveAccountGrantRevoked $event): void
    {
        SpecialLeaveGrant::query()->whereKey($event->grantId)->update([
            'status' => SpecialLeaveGrantStatus::REVOKED,
            'revoked_at' => $event->createdAt(),
            'revoked_by_user_id' => $event->revokedByUserId,
            'revoke_reason' => $event->reason,
        ]);
    }

    public function onSpecialLeaveAccountUsageDesignated(SpecialLeaveAccountUsageDesignated $event): void
    {
        $this->assertUserId($event->userId, $event->usageId);

        // 同じ申請に旧系統(usage_idを持たない)の消化記録が残っていれば置き換える。
        $this->deleteLegacyUsages($event->requestId);

        SpecialLeaveUsage::query()->updateOrCreate(
            ['usage_id' => $event->usageId],
            $this->usageAttributes(
                userId: $event->userId,
                requestId: $event->requestId,
                usedOn: $event->usedOn,
                usedDays: $event->usedDays,
                usedMinutes: $event->usedMinutes,
                usageType: $event->usageType,
                isConfirmed: false,
                unallocatedDays: 0.0,
            ),
        );
    }

    public function onSpecialLeaveAccountUsageConfirmed(SpecialLeaveAccountUsageConfirmed $event): void
    {
        $usage = SpecialLeaveUsage::query()->where('usage_id', $event->usageId)->first();

        if ($usage === null) {
            return;
        }

        $grantIds = $this->replaceAllocations($event->usageId, $event->allocations);

        $usage->update([
            'is_confirmed' => true,
            'unallocated_days' => $event->unallocatedDays,
            'special_leave_grant_id' => count($grantIds) === 1 ? $grantIds[0] : null,
        ]);

        $this->recalculateGrants($grantIds);
    }

    public function onSpecialLeaveAccountUsageCancelled(SpecialLeaveAccountUsageCancelled $event): void
    {
        $grantIds = SpecialLeaveUsageAllocation::query()
            ->where('usage_id', $event->usageId)
            ->pluck('grant_id')
            ->map(fn ($grantId) => (string) $grantId)
            ->all();

        SpecialLeaveUsageAllocation::query()->where('usage_id', $event->usageId)->delete();
        SpecialLeaveUsage::query()->where('usage_id', $event->usageId)->delete();

        $this->recalculateGrants($grantIds);
    }

    /**
     * 移行(special_leave_account.migrated)。本変更前の付与・消化記録の現在の状態を元のIDで書き込む。
     * 移行より前の旧系統のイベントで作った行は、同じ付与IDなら上書き、同じ申請の旧系統の消化記録は置き換える。
     */
    public function onSpecialLeaveAccountMigrated(SpecialLeaveAccountMigrated $event): void
    {
        $this->assertUserId($event->userId, 'migrated');

        $grantIds = [];

        foreach ($event->grants as $grant) {
            SpecialLeaveGrant::query()->updateOrCreate(
                ['id' => $grant['grantId']],
                [
                    'user_id' => $event->userId,
                    'special_leave_type_id' => $grant['specialLeaveTypeId'],
                    'granted_on' => $grant['grantedOn'],
                    'expires_on' => $grant['expiresOn'],
                    'granted_days' => $grant['grantedDays'],
                    'used_days' => 0,
                    'remaining_days' => $grant['grantedDays'],
                    'status' => $grant['revoked'] ? SpecialLeaveGrantStatus::REVOKED : SpecialLeaveGrantStatus::ACTIVE,
                    'revoked_at' => $grant['revoked'] ? $event->createdAt() : null,
                ],
            );

            $grantIds[] = $grant['grantId'];
        }

        foreach ($event->usages as $usage) {
            $this->deleteLegacyUsages($usage['requestId']);

            SpecialLeaveUsage::query()->updateOrCreate(
                ['usage_id' => $usage['usageId']],
                $this->usageAttributes(
                    userId: $event->userId,
                    requestId: $usage['requestId'],
                    usedOn: $usage['usedOn'],
                    usedDays: $usage['usedDays'],
                    usedMinutes: $usage['usedMinutes'],
                    usageType: $usage['usageType'],
                    isConfirmed: $usage['status'] === 'confirmed',
                    unallocatedDays: 0.0,
                ),
            );

            $grantIds = array_merge($grantIds, $this->replaceAllocations($usage['usageId'], $usage['allocations']));
        }

        $this->recalculateGrants(array_values(array_unique($grantIds)));
    }

    /**
     * 消化記録の行(特別休暇の申請1件に対応する消化記録)の属性。勤怠日・申請テーブルへの外部キーは持たせない。
     *
     * @return array<string, mixed>
     */
    private function usageAttributes(
        string $userId,
        string $requestId,
        string $usedOn,
        float $usedDays,
        ?int $usedMinutes,
        string $usageType,
        bool $isConfirmed,
        float $unallocatedDays,
    ): array {
        return [
            'user_id' => $userId,
            'attendance_day_id' => null,
            'special_leave_grant_id' => null,
            'special_leave_request_id' => $requestId,
            'used_on' => $usedOn,
            'used_days' => $usedDays,
            'used_minutes' => $usedMinutes,
            'usage_type' => $usageType,
            'is_confirmed' => $isConfirmed,
            'unallocated_days' => $unallocatedDays,
        ];
    }

    /**
     * 消化記録の充当を置き換える(付与IDの一覧を返す。残数の再計算に使う)。
     *
     * @param  array<int, array{grantId: string, allocatedDays: float}>  $allocations
     * @return string[]
     */
    private function replaceAllocations(string $usageId, array $allocations): array
    {
        $previous = SpecialLeaveUsageAllocation::query()
            ->where('usage_id', $usageId)
            ->pluck('grant_id')
            ->map(fn ($grantId) => (string) $grantId)
            ->all();

        SpecialLeaveUsageAllocation::query()->where('usage_id', $usageId)->delete();

        $grantIds = $previous;

        foreach ($allocations as $allocation) {
            SpecialLeaveUsageAllocation::query()->create([
                'usage_id' => $usageId,
                'grant_id' => $allocation['grantId'],
                'allocated_days' => $allocation['allocatedDays'],
            ]);

            $grantIds[] = (string) $allocation['grantId'];
        }

        return array_values(array_unique($grantIds));
    }

    /** 同じ申請の、充当の表に紐づかない(旧系統の)消化記録の行を削除する。 */
    private function deleteLegacyUsages(string $requestId): void
    {
        SpecialLeaveUsage::query()
            ->where('special_leave_request_id', $requestId)
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
     * 付与の消化済み日数・残数を充当の表の合計から計算し直す(表示・残数判定用の非正規化キャッシュ。
     * 正は充当の表とイベント)。付与の行が無ければ何もしない。
     */
    private function recalculateGrant(string $grantId): void
    {
        $grant = SpecialLeaveGrant::query()->find($grantId);

        if ($grant === null) {
            return;
        }

        $usedDays = (float) SpecialLeaveUsageAllocation::query()
            ->where('grant_id', $grantId)
            ->sum('allocated_days');

        $grant->update([
            'used_days' => $usedDays,
            'remaining_days' => (float) $grant->granted_days - $usedDays,
        ]);
    }

    private function assertUserId(?string $userId, string $label): void
    {
        if ($userId === null) {
            throw new \LogicException("特別休暇口座のイベントに利用者IDがありません: {$label}");
        }
    }
}
