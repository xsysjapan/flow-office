<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\PaidLeaveAccount\Support\AllocationPlanner;
use App\Domain\PaidLeaveAccount\Support\GrantTimeAllocationPlanner;

/**
 * 作り直し後の口座のイベントに、現行の充当の規則(論点19 決定10-1)を発生順に過去へ当てはめ、
 * usage_allocated・usage_allocation_released を導出する(assets/event-rebuild-mapping.md 2.3)。
 *
 * - 確定時の充当(rule: confirm): usage_confirmed の時点で、その消化記録に入力全体を通じて usage_allocated が1件も無いなら、
 *   その時点の利用者の付与状態(grantedOn・expiresOn・付与日数・取消・充当済み合計)に `AllocationPlanner::plan` を当てはめ、
 *   使用日数 − 充当済み の範囲で充当する(現行の `PaidLeaveAccountAggregate::allocateUsage` と同じ規則)。
 * - 付与時の充当(rule: grant): grant_created の時点で、それより前に確定した(usage_confirmed)未取消の消化記録のうち、
 *   充当が使用日数に足りないものへ、確定の古い順に、その付与の残りの範囲で充当する(`GrantTimeAllocationPlanner`。
 *   現行の `allocateUnallocatedOnGrant` と同じ規則)。付与の有効期間と消化記録の対象日の関係は問わない。
 * - 取消(rule: cancel): usage_cancelled の時点で、その消化記録に残っている充当(付与ごと)を解除する(現行の `cancelUsage`)。
 * - 付与の取消(rule: grant_revoked): grant_revoked の時点で、その付与に残っている充当(消化記録ごと)を解除してから取消を記録する
 *   (現行の `revokeGrant`)。
 * - 付与の増額・付与日の変更・期限延長(rule: grant_changed): grant_amount_changed・grant_date_changed・grant_expiry_changed の
 *   時点で、現行の集約と同じく未充当の再充当を行う。解除は行わない(現行も減額で充当を解除しない)。
 * - 解除の二重防止: 解除は、入力中でその契機より後に既存の解除(usage_allocation_released)がある消化記録・付与の分は導出しない。
 *   再充当の対象は、入力中に既存の usage_allocated を1件でも持つ消化記録を除く(確定時と同じ)。
 * - 取消・付与の取消による解除の対象は、取消(revoked)済みの付与を除く。付与の取消の時点で、その付与の充当は解除済みになるため。
 * - 再充当(`cancel`・`grant_changed`・`grant_revoked`)は、確定済み・未取消で充当が足りない消化記録を、usedOn の昇順
 *   (同日は消化の指定の順。現行の `allocateUnallocatedUsages` と同じ並べ方)に、`AllocationPlanner` で充当する。
 * - 付与日・期限は grant_created の後の grant_date_changed・grant_expiry_changed で更新する。付与日数は grant_amount_changed の
 *   時点までの値。取消(grant_revoked)済みの付与には充当しない。
 * - 既存の充当・解除・取消、導出した充当・解除は、順に状態へ反映する。付与の無い利用者の未充当はそのまま。
 * - 置く位置: 確定時・付与時の充当と、変更系の再充当は契機のイベントの直後(同じ anchor の中で subOrder = SUB_ORDER_BASE から)。
 *   取消・付与の取消の解除は、契機の行より前(同じ anchor の中で元の行より前、subOrder は負の値)。ただし契機の anchor に
 *   充当・導出の契機となる行が他にあるときは直後。取消・付与の取消の再充当は契機の直後。
 *   発生日時・meta は契機のイベントから引き継ぎ、meta の cutover は除く。
 */
final class UnallocatedUsageAllocator
{
    /** 置き場所の行(anchor)の中で、元の行より後ろに置くための subOrder の開始値。 */
    public const SUB_ORDER_BASE = 100;

    /** 置き場所の行(anchor)の中で、元の行より前に置くための subOrder の起点(負の値。ReconcileContext と同じ)。 */
    private const BEFORE_BASE = -1000000;

    private const EPSILON = 0.000001;

    /** 充当・解除を導出しうるイベント(同じ anchor にこれが他にあると、解除を元の行より前に置かない)。 */
    private const CAPABLE_CLASSES = [
        'paid_leave_account.usage_confirmed',
        'paid_leave_account.usage_cancelled',
        'paid_leave_account.grant_created',
        'paid_leave_account.grant_amount_changed',
        'paid_leave_account.grant_date_changed',
        'paid_leave_account.grant_expiry_changed',
        'paid_leave_account.grant_revoked',
    ];

    /**
     * @param  list<RebuiltEvent>  $events  作り直し後の口座のイベント(順不同でよい)
     * @return array{events: list<RebuiltEvent>, report: array<string, mixed>}
     */
    public function allocate(array $events): array
    {
        $sorted = array_values(array_filter($events, fn (RebuiltEvent $e) => $e->aggregateUuid !== null));
        usort($sorted, fn (RebuiltEvent $a, RebuiltEvent $b) => [$a->anchorId, $a->subOrder] <=> [$b->anchorId, $b->subOrder]);

        // 入力全体の事前走査。
        $hasAllocation = [];        // 既存の usage_allocated を持つ消化記録
        $allocationAnchors = [];    // 充当の行(usage_allocated)のある anchor
        $capableCounts = [];        // anchor => 充当・解除を導出しうる行の件数
        $releasesByUsage = [];      // 消化記録ID => [[発生順の位置, 付与ID], ...](既存の解除)
        foreach ($sorted as $index => $event) {
            $payload = $event->payload;
            if (in_array($event->eventClass, self::CAPABLE_CLASSES, true)) {
                $capableCounts[$event->anchorId] = ($capableCounts[$event->anchorId] ?? 0) + 1;
            }
            switch ($event->eventClass) {
                case 'paid_leave_account.usage_allocated':
                    $hasAllocation[(string) $payload['usageId']] = true;
                    $allocationAnchors[$event->anchorId] = true;
                    break;
                case 'paid_leave_account.usage_allocation_released':
                    $releasesByUsage[(string) $payload['usageId']][] = [$index, (string) $payload['grantId']];
                    break;
                default:
                    break;
            }
        }

        /** @var array<string, array{userId: string, usedOn: ?string, usedDays: float, order: int, confirmOrder: ?int, cancelled: bool, allocated: float, byGrant: array<string, float>, derived: list<array{grantId: string, days: float, rule: string}>}> $usages */
        $usages = [];
        /** @var array<string, array<string, array{grantedOn: string, expiresOn: string, days: float, revoked: bool, allocated: float}>> $grants 利用者ID => 付与ID => 状態 */
        $grants = [];
        $confirmSequence = 0;
        $designationSequence = 0;
        $nextSub = [];
        $beforeSub = [];
        $derived = [];
        $grantPlanner = new GrantTimeAllocationPlanner;
        $confirmPlanner = new AllocationPlanner;

        // 導出イベントを元の行の直後に置く(同じ anchor の中で SUB_ORDER_BASE から)。
        $after = function (RebuiltEvent $source) use (&$nextSub): int {
            $sub = $nextSub[$source->anchorId] ?? self::SUB_ORDER_BASE;
            $nextSub[$source->anchorId] = $sub + 1;

            return $sub;
        };
        // 導出イベントを元の行より前に置く(同じ anchor の中で負の subOrder から)。
        $before = function (RebuiltEvent $source) use (&$beforeSub): int {
            $sub = $beforeSub[$source->anchorId] ?? self::BEFORE_BASE;
            $beforeSub[$source->anchorId] = $sub + 1;

            return $sub;
        };
        $emit = function (RebuiltEvent $source, int $subOrder, string $eventClass, string $userId, array $payload) use (&$derived): void {
            $meta = $source->meta;
            unset($meta['cutover']);
            $derived[] = new RebuiltEvent(
                eventClass: $eventClass,
                aggregateUuid: $userId,
                payload: $payload,
                meta: $meta,
                createdAt: $source->createdAt,
                anchorId: $source->anchorId,
                subOrder: $subOrder,
                rebuiltFrom: $source->anchorId,
                sourceVersion: null,
            );
        };
        // 充当を導出し、状態(消化記録・付与)と報告へ反映する。
        $derive = function (RebuiltEvent $source, string $userId, string $usageId, string $grantId, float $days, string $rule) use (&$usages, &$grants, $after, $emit): void {
            $emit($source, $after($source), 'paid_leave_account.usage_allocated', $userId, [
                'usageId' => $usageId,
                'grantId' => $grantId,
                'allocatedDays' => $days,
            ]);
            $this->addAllocation($usages, $grants, $userId, $usageId, $grantId, $days);
            $usages[$usageId]['derived'][] = ['grantId' => $grantId, 'days' => $days, 'rule' => $rule];
        };
        // 未充当の消化記録へ、AllocationPlanner で充当する(現行の allocateUnallocatedUsages と同じ並べ方・規則)。
        $reallocate = function (RebuiltEvent $source, string $userId, string $rule) use (&$usages, &$grants, $hasAllocation, $confirmPlanner, $derive): void {
            foreach ($this->pendingForReallocation($usages, $hasAllocation, $userId) as $pendingId) {
                $pending = $usages[$pendingId];
                $remaining = $pending['usedDays'] - $pending['allocated'];
                if ($remaining <= 0) {
                    continue;
                }
                $plan = $confirmPlanner->plan(
                    (string) $pending['usedOn'],
                    $remaining,
                    $this->grantsSnapshot($grants[$userId] ?? []),
                );
                foreach ($plan as $entry) {
                    if ($entry['allocatedDays'] <= 0) {
                        continue;
                    }
                    $derive($source, $userId, $pendingId, $entry['grantId'], $entry['allocatedDays'], $rule);
                }
            }
        };

        foreach ($sorted as $index => $event) {
            $userId = (string) $event->aggregateUuid;
            $payload = $event->payload;
            switch ($event->eventClass) {
                case 'paid_leave_account.usage_designated':
                    $usages[(string) $payload['usageId']] = [
                        'userId' => $userId,
                        'usedOn' => substr((string) $payload['usedOn'], 0, 10),
                        'usedDays' => (float) $payload['usedDays'],
                        'order' => $designationSequence++,
                        'confirmOrder' => null,
                        'cancelled' => false,
                        'allocated' => 0.0,
                        'byGrant' => [],
                        'derived' => [],
                    ];
                    break;
                case 'paid_leave_account.usage_confirmed':
                    $usageId = (string) $payload['usageId'];
                    if (! isset($usages[$usageId])) {
                        break;
                    }
                    $usages[$usageId]['confirmOrder'] = $confirmSequence++;

                    // 確定時の充当(規則 confirm)。既存の充当を持つ消化記録は導出しない。
                    $usage = $usages[$usageId];
                    if (isset($hasAllocation[$usageId]) || $usage['cancelled']) {
                        break;
                    }
                    $remaining = $usage['usedDays'] - $usage['allocated'];
                    if ($remaining <= 0) {
                        break;
                    }
                    $plan = $confirmPlanner->plan(
                        (string) $usage['usedOn'],
                        $remaining,
                        $this->grantsSnapshot($grants[$userId] ?? []),
                    );
                    foreach ($plan as $entry) {
                        if ($entry['allocatedDays'] <= 0) {
                            continue;
                        }
                        $derive($event, $userId, $usageId, $entry['grantId'], $entry['allocatedDays'], 'confirm');
                    }
                    break;
                case 'paid_leave_account.usage_cancelled':
                    $usageId = (string) $payload['usageId'];
                    if (! isset($usages[$usageId]) || $usages[$usageId]['cancelled']) {
                        break;
                    }
                    $usages[$usageId]['cancelled'] = true;

                    // 取消の解除(規則 cancel): 残っている充当を付与ごとに解除する。取消済みの付与(revoked)の分は、
                    // 付与の取消の時点で解除済みのため除く。取消より後に既存の解除がある付与の分は二重に解除しない。
                    $releases = [];
                    foreach ($usages[$usageId]['byGrant'] as $grantId => $days) {
                        $grantId = (string) $grantId;
                        if ($days <= self::EPSILON) {
                            continue;
                        }
                        $grant = $grants[$userId][$grantId] ?? null;
                        if ($grant === null || $grant['revoked']) {
                            continue;
                        }
                        if ($this->hasLaterRelease($releasesByUsage[$usageId] ?? [], $index, $grantId)) {
                            continue;
                        }
                        $releases[] = [$grantId, $days];
                    }
                    $placeBefore = $this->placeBeforeExit($event, $allocationAnchors, $capableCounts);
                    foreach ($releases as [$grantId, $days]) {
                        $emit($event, $placeBefore ? $before($event) : $after($event), 'paid_leave_account.usage_allocation_released', $userId, [
                            'usageId' => $usageId,
                            'grantId' => $grantId,
                            'releasedDays' => $days,
                        ]);
                        $this->addAllocation($usages, $grants, $userId, $usageId, $grantId, -$days);
                    }

                    $reallocate($event, $userId, 'cancel');
                    break;
                case 'paid_leave_account.grant_revoked':
                    $grantId = (string) $payload['grantId'];
                    if (! isset($grants[$userId][$grantId]) || $grants[$userId][$grantId]['revoked']) {
                        break;
                    }

                    // 付与の取消(規則 grant_revoked。現行の revokeGrant): 付与に残っている充当を消化記録ごとに解除してから取消を記録する。
                    // 入力中で取消より後に既存の解除がある消化記録の分は二重に解除しない。
                    $releases = [];
                    foreach ($usages as $usageId => $usage) {
                        if ($usage['userId'] !== $userId || $usage['cancelled']) {
                            continue;
                        }
                        $days = $usage['byGrant'][$grantId] ?? 0.0;
                        if ($days <= self::EPSILON) {
                            continue;
                        }
                        if ($this->hasLaterRelease($releasesByUsage[(string) $usageId] ?? [], $index, $grantId)) {
                            continue;
                        }
                        $releases[] = [(string) $usageId, $days];
                    }
                    $placeBefore = $this->placeBeforeExit($event, $allocationAnchors, $capableCounts);
                    foreach ($releases as [$usageId, $days]) {
                        $emit($event, $placeBefore ? $before($event) : $after($event), 'paid_leave_account.usage_allocation_released', $userId, [
                            'usageId' => $usageId,
                            'grantId' => $grantId,
                            'releasedDays' => $days,
                        ]);
                        $this->addAllocation($usages, $grants, $userId, $usageId, $grantId, -$days);
                    }

                    $grants[$userId][$grantId]['revoked'] = true;
                    $reallocate($event, $userId, 'grant_revoked');
                    break;
                case 'paid_leave_account.usage_allocated':
                    $this->addAllocation($usages, $grants, $userId, (string) $payload['usageId'], (string) $payload['grantId'], (float) $payload['allocatedDays']);
                    break;
                case 'paid_leave_account.usage_allocation_released':
                    $this->addAllocation($usages, $grants, $userId, (string) $payload['usageId'], (string) $payload['grantId'], -(float) $payload['releasedDays']);
                    break;
                case 'paid_leave_account.grant_amount_changed':
                    if (isset($grants[$userId][(string) $payload['grantId']])) {
                        $grants[$userId][(string) $payload['grantId']]['days'] = (float) $payload['newGrantedDays'];
                        $reallocate($event, $userId, 'grant_changed');
                    }
                    break;
                case 'paid_leave_account.grant_date_changed':
                    if (isset($grants[$userId][(string) $payload['grantId']])) {
                        $grants[$userId][(string) $payload['grantId']]['grantedOn'] = substr((string) $payload['newGrantedOn'], 0, 10);
                        $reallocate($event, $userId, 'grant_changed');
                    }
                    break;
                case 'paid_leave_account.grant_expiry_changed':
                    if (isset($grants[$userId][(string) $payload['grantId']])) {
                        $grants[$userId][(string) $payload['grantId']]['expiresOn'] = substr((string) $payload['newExpiresOn'], 0, 10);
                        $reallocate($event, $userId, 'grant_changed');
                    }
                    break;
                case 'paid_leave_account.grant_created':
                    $grantId = (string) $payload['grantId'];
                    $grants[$userId][$grantId] = [
                        'grantedOn' => substr((string) $payload['grantedOn'], 0, 10),
                        'expiresOn' => substr((string) $payload['expiresOn'], 0, 10),
                        'days' => (float) $payload['grantedDays'],
                        'revoked' => false,
                        'allocated' => 0.0,
                    ];

                    // 付与時の充当(規則 grant)。この付与より前に確定した未取消の消化記録の、不足分へ充当する。
                    $pending = [];
                    foreach ($usages as $usageId => $usage) {
                        if ($usage['userId'] !== $userId || $usage['confirmOrder'] === null || $usage['cancelled']) {
                            continue;
                        }
                        $pending[] = ['usageId' => (string) $usageId, 'remainingDays' => $usage['usedDays'] - $usage['allocated'], 'confirmOrder' => $usage['confirmOrder']];
                    }
                    $plan = $grantPlanner->plan($grants[$userId][$grantId]['days'] - $grants[$userId][$grantId]['allocated'], $pending);
                    foreach ($plan as $entry) {
                        $derive($event, $userId, $entry['usageId'], $grantId, $entry['allocatedDays'], 'grant');
                    }
                    break;
                default:
                    break;
            }
        }

        return ['events' => $derived, 'report' => $this->report($usages, $grants)];
    }

    /**
     * 解除(取消・付与の取消)を契機の行より前に置けるか。同じ anchor に充当の行や、充当・解除を導出しうる行が
     * 契機の行の他に無いときだけ前に置く。
     *
     * @param  array<int, true>  $allocationAnchors
     * @param  array<int, int>  $capableCounts
     */
    private function placeBeforeExit(RebuiltEvent $exit, array $allocationAnchors, array $capableCounts): bool
    {
        return ! isset($allocationAnchors[$exit->anchorId]) && ($capableCounts[$exit->anchorId] ?? 0) === 1;
    }

    /**
     * 取消後・付与変更後の再充当の対象(確定済み・未取消・既存の充当なし・不足あり)を、usedOn 昇順・同日は消化の指定の順に並べた消化記録ID。
     * 現行の `allocateUnallocatedUsages` と同じ並べ方。
     *
     * @param  array<string, array<string, mixed>>  $usages
     * @param  array<string, true>  $hasAllocation
     * @return list<string>
     */
    private function pendingForReallocation(array $usages, array $hasAllocation, string $userId): array
    {
        $pending = [];
        foreach ($usages as $usageId => $usage) {
            if ($usage['userId'] !== $userId || $usage['confirmOrder'] === null || $usage['cancelled']) {
                continue;
            }
            if (isset($hasAllocation[(string) $usageId]) || $usage['usedDays'] - $usage['allocated'] <= 0) {
                continue;
            }
            $pending[] = ['id' => (string) $usageId, 'usedOn' => (string) $usage['usedOn'], 'order' => $usage['order']];
        }
        usort($pending, fn (array $a, array $b) => [$a['usedOn'], $a['order']] <=> [$b['usedOn'], $b['order']]);

        return array_column($pending, 'id');
    }

    /**
     * 解除の契機の発生位置より後に、その消化記録・付与の既存の解除があるか。
     *
     * @param  list<array{0: int, 1: string}>  $releases  [発生順の位置, 付与ID]
     */
    private function hasLaterRelease(array $releases, int $exitIndex, string $grantId): bool
    {
        foreach ($releases as [$position, $releasedGrantId]) {
            if ($position > $exitIndex && $releasedGrantId === $grantId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 消化記録ごとの一覧: 導出した充当がある、または確定済み・未取消で未充当が残るもの。
     *
     * @param  array<string, array<string, mixed>>  $usages
     * @param  array<string, array<string, array{grantedOn: string, expiresOn: string, days: float, revoked: bool, allocated: float}>>  $grants
     * @return array<string, mixed>
     */
    private function report(array $usages, array $grants): array
    {
        $report = [
            'allocated_usages' => 0,
            'allocated_days' => 0.0,
            'unallocated_usages' => 0,
            'unallocated_days' => 0.0,
            'unallocated' => [],
            'usages' => [],
        ];
        foreach ($usages as $usageId => $usage) {
            if ($usage['confirmOrder'] === null || $usage['cancelled']) {
                continue;
            }
            $derived = $usage['derived'] ?? [];
            $shortfall = max(0.0, $usage['usedDays'] - $usage['allocated']);
            $hasShortfall = $shortfall > self::EPSILON;
            if ($derived === [] && ! $hasShortfall) {
                continue;
            }
            // 付与ID・規則ごとにまとめる(どの規則で充当したかを残す: confirm / grant / cancel / grant_changed / grant_revoked)。
            $allocatedNow = 0.0;
            $allocations = [];
            foreach ($derived as $entry) {
                $allocatedNow += $entry['days'];
                $key = $entry['grantId'].'|'.$entry['rule'];
                $allocations[$key] ??= ['grant_id' => $entry['grantId'], 'days' => 0.0, 'rule' => $entry['rule']];
                $allocations[$key]['days'] += $entry['days'];
            }
            $allocations = array_values($allocations);
            $reason = null;
            if ($hasShortfall) {
                $reason = ($grants[$usage['userId']] ?? []) === [] ? '付与なし' : '付与の残り不足';
            }
            $report['usages'][] = [
                'usage_id' => (string) $usageId,
                'user_id' => $usage['userId'],
                'used_on' => $usage['usedOn'],
                'days' => $usage['usedDays'],
                'allocations' => $allocations,
                'unallocated_days' => $shortfall,
                'reason' => $reason,
            ];
            if ($allocatedNow > self::EPSILON) {
                $report['allocated_usages']++;
                $report['allocated_days'] += $allocatedNow;
            }
            if ($hasShortfall) {
                $report['unallocated_usages']++;
                $report['unallocated_days'] += $shortfall;
                $report['unallocated'][] = [
                    'user_id' => $usage['userId'],
                    'usage_id' => (string) $usageId,
                    'used_on' => $usage['usedOn'],
                    'days' => $shortfall,
                    'reason' => $reason,
                ];
            }
        }

        return $report;
    }

    /**
     * 集約の grantsSnapshot と同じ形(AllocationPlanner の入力)にする。
     *
     * @param  array<string, array{grantedOn: string, expiresOn: string, days: float, revoked: bool, allocated: float}>  $userGrants
     * @return list<array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocatedTotal: float}>
     */
    private function grantsSnapshot(array $userGrants): array
    {
        $snapshot = [];
        foreach ($userGrants as $grantId => $grant) {
            $snapshot[] = [
                'grantId' => (string) $grantId,
                'grantedOn' => $grant['grantedOn'],
                'expiresOn' => $grant['expiresOn'],
                'grantedDays' => $grant['days'],
                'revoked' => $grant['revoked'],
                'allocatedTotal' => $grant['allocated'],
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<string, array<string, mixed>>  $usages
     * @param  array<string, array<string, array{grantedOn: string, expiresOn: string, days: float, revoked: bool, allocated: float}>>  $grants
     */
    private function addAllocation(array &$usages, array &$grants, string $userId, string $usageId, string $grantId, float $days): void
    {
        if (isset($usages[$usageId])) {
            $usages[$usageId]['allocated'] += $days;
            $usages[$usageId]['byGrant'][$grantId] = ($usages[$usageId]['byGrant'][$grantId] ?? 0.0) + $days;
        }
        if (isset($grants[$userId][$grantId])) {
            $grants[$userId][$grantId]['allocated'] += $days;
        }
    }
}
