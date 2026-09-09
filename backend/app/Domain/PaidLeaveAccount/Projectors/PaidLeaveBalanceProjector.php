<?php

namespace App\Domain\PaidLeaveAccount\Projectors;

use App\Domain\PaidLeaveAccount\Events\PaidLeaveAccountMigrated;
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
use App\Models\PaidLeaveBalance;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * 社員単位の現在残高キャッシュ`paid_leave_balances`を維持する。表示専用であり、
 * 承認可否等の業務判定の根拠には使わない(判定は必ずCommandHandlerが
 * `PaidLeaveAccountAggregate`をreplayして行う)。
 *
 * すべての更新契機で、この集約(aggregate_uuid = userId)に記録された
 * `paid_leave_account.*`イベント全件をstored_eventsから読み直して集計し直す
 * (`PaidLeaveGrantProjector::recalculate()`と同じ「都度全件再集計」方式。Projectorの
 * 再適用・`event-sourcing:replay`に対して冪等)。
 * - available_days: 取消されていない各Grantの`grantedDays - allocatedTotal`の合計
 *   (有効期限切れの除外はここでは行わない。表示側で必要に応じてフィルタする)。
 * - unallocated_days: 確定済み・未取消のUsageのうち`usedDays - allocatedTotal`が
 *   正の値(＝残高不足で未充当のまま残っている分)の合計。
 * - pending_days: 確定前(designateUsage直後でconfirm未実行)のUsageのusedDays合計。
 * - next_grant_scheduled_on: Scheduleドメイン(Phase 7以降)導入までnullのまま置く。
 */
class PaidLeaveBalanceProjector extends Projector
{
    public function onPaidLeaveGrantCreated(PaidLeaveGrantCreated $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    /**
     * 最終Phase(データ移行)。cutover専用の一括初期化イベントも他イベントと同様、
     * 都度全件再集計に含める(recalculate()内のmatchへ'paid_leave_account.migrated'を追加)。
     */
    public function onPaidLeaveAccountMigrated(PaidLeaveAccountMigrated $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveGrantAmountChanged(PaidLeaveGrantAmountChanged $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveGrantDateChanged(PaidLeaveGrantDateChanged $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveGrantExpiryChanged(PaidLeaveGrantExpiryChanged $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveGrantRevoked(PaidLeaveGrantRevoked $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveUsageDesignated(PaidLeaveUsageDesignated $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveUsageConfirmed(PaidLeaveUsageConfirmed $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveUsageCancelled(PaidLeaveUsageCancelled $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveUsageAllocated(PaidLeaveUsageAllocated $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveUsageAllocationReleased(PaidLeaveUsageAllocationReleased $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    private function recalculate(string $userId): void
    {
        $events = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $userId)
            ->where('event_class', 'like', 'paid_leave_account.%')
            ->orderBy('id')
            ->get();

        $grants = [];
        $usages = [];

        foreach ($events as $storedEvent) {
            $props = $storedEvent->event_properties;

            match ($storedEvent->event_class) {
                'paid_leave_account.grant_created' => $grants[$props['grantId']] = [
                    'grantedDays' => (float) $props['grantedDays'],
                    'revoked' => false,
                    'allocated' => 0.0,
                ],
                // 移行Grantの以後の消化上限は常にremainingDaysAtCutoverそのもの
                // (PaidLeaveAccountAggregate::migrateGrantsのdoc参照。originalGrantedDaysは
                // 表示・監査専用の付随情報であり、ここでの上限計算には使わない)。
                'paid_leave_account.migrated' => (function () use (&$grants, $props) {
                    foreach ($props['grants'] as $g) {
                        $grants[$g['grantId']] = [
                            'grantedDays' => (float) $g['remainingDaysAtCutover'],
                            'revoked' => false,
                            'allocated' => 0.0,
                        ];
                    }
                })(),
                'paid_leave_account.grant_amount_changed' => $grants[$props['grantId']]['grantedDays']
                    = (float) $props['newGrantedDays'],
                'paid_leave_account.grant_revoked' => $grants[$props['grantId']]['revoked'] = true,
                'paid_leave_account.usage_designated' => $usages[$props['usageId']] = [
                    'usedDays' => (float) $props['usedDays'],
                    'confirmed' => false,
                    'cancelled' => false,
                    'allocated' => 0.0,
                ],
                'paid_leave_account.usage_confirmed' => $usages[$props['usageId']]['confirmed'] = true,
                'paid_leave_account.usage_cancelled' => $usages[$props['usageId']]['cancelled'] = true,
                'paid_leave_account.usage_allocated' => (function () use (&$grants, &$usages, $props) {
                    $grants[$props['grantId']]['allocated'] = ($grants[$props['grantId']]['allocated'] ?? 0.0) + (float) $props['allocatedDays'];
                    $usages[$props['usageId']]['allocated'] = ($usages[$props['usageId']]['allocated'] ?? 0.0) + (float) $props['allocatedDays'];
                })(),
                'paid_leave_account.usage_allocation_released' => (function () use (&$grants, &$usages, $props) {
                    $grants[$props['grantId']]['allocated'] = ($grants[$props['grantId']]['allocated'] ?? 0.0) - (float) $props['releasedDays'];
                    $usages[$props['usageId']]['allocated'] = ($usages[$props['usageId']]['allocated'] ?? 0.0) - (float) $props['releasedDays'];
                })(),
                default => null,
            };
        }

        $availableDays = 0.0;
        foreach ($grants as $grant) {
            if ($grant['revoked']) {
                continue;
            }
            $availableDays += $grant['grantedDays'] - $grant['allocated'];
        }

        $pendingDays = 0.0;
        $unallocatedDays = 0.0;
        foreach ($usages as $usage) {
            if ($usage['cancelled']) {
                continue;
            }
            if (! $usage['confirmed']) {
                $pendingDays += $usage['usedDays'];

                continue;
            }
            $shortfall = $usage['usedDays'] - $usage['allocated'];
            if ($shortfall > 0) {
                $unallocatedDays += $shortfall;
            }
        }

        PaidLeaveBalance::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'available_days' => $availableDays,
                'pending_days' => $pendingDays,
                'unallocated_days' => $unallocatedDays,
                'next_grant_scheduled_on' => null,
            ],
        );
    }
}
