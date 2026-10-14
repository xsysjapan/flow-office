<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\PaidLeaveAccount\Support\AllocationPlanner;

/**
 * cutover前に承認され、充当(旧 paid_leave.used)が無いまま確定した有給の消化記録を、cutover の付与
 * (carried_over の grant_created)に充当する usage_allocated を導出する(論点19 決定9-3a、
 * assets/event-rebuild-mapping.md 2.3)。
 *
 * - 対象: 口座集約(userId)の usage_confirmed のうち、確定の発生日が cutoverDate より前、usage_allocated・usage_cancelled が
 *   無いもの。cutover後の通常の承認(既に usage_allocated があるもの・cutover後に確定したもの)は対象外。
 * - 充当の順序・単位: 現行の口座集約の承認時の充当と同じ(確定の順に、AllocationPlanner で対象日に有効な付与の
 *   expiresOn 昇順。付与の残りを超えない。足りない分は未充当のまま。論点17)。充当の候補は cutover の付与だけ。
 * - 置く位置: その付与の grant_created と、その消化の usage_confirmed のうち後ろの直後(subOrder で順序)。
 *   発生日時・meta は置き場所の行から引き継ぐ。
 */
final class CutoverUsageAllocator
{
    /** 置き場所の行(anchor)の中で、元の行より後ろに置くための subOrder の開始値。 */
    public const SUB_ORDER_BASE = 100;

    private const EPSILON = 0.000001;

    /**
     * @param  list<RebuiltEvent>  $events  変換後の休暇のイベント(申請の変換結果・cutoverの付与を含む)
     * @param  array<string, array{cutoverDate: string, grants: list<RebuiltEvent>}>  $cutovers  利用者ID => cutoverの日付と、cutoverの付与の grant_created
     * @return array{events: list<RebuiltEvent>, report: array{eligible_usages: int, allocated_usages: int, allocated_days: float, unallocated_usages: int, unallocated_days: float, unallocated: list<array{user_id: string, usage_id: string, used_on: string, days: float, reason: string}>}}
     */
    public function allocate(array $events, array $cutovers): array
    {
        $report = [
            'eligible_usages' => 0,
            'allocated_usages' => 0,
            'allocated_days' => 0.0,
            'unallocated_usages' => 0,
            'unallocated_days' => 0.0,
            'unallocated' => [],
        ];
        if ($cutovers === []) {
            return ['events' => [], 'report' => $report];
        }

        /** @var array<string, array{userId: string, designated: ?RebuiltEvent, confirmed: ?RebuiltEvent, allocated: bool, cancelled: bool}> $usages */
        $usages = [];
        foreach ($events as $event) {
            $userId = $event->aggregateUuid;
            if ($userId === null || ! isset($cutovers[$userId])) {
                continue;
            }
            $usageId = $event->payload['usageId'] ?? null;
            if ($usageId === null) {
                continue;
            }
            $usageId = (string) $usageId;
            $usages[$usageId] ??= ['userId' => $userId, 'designated' => null, 'confirmed' => null, 'allocated' => false, 'cancelled' => false];
            match ($event->eventClass) {
                'paid_leave_account.usage_designated' => $usages[$usageId]['designated'] = $event,
                'paid_leave_account.usage_confirmed' => $usages[$usageId]['confirmed'] = $event,
                'paid_leave_account.usage_allocated' => $usages[$usageId]['allocated'] = true,
                'paid_leave_account.usage_cancelled' => $usages[$usageId]['cancelled'] = true,
                default => null,
            };
        }

        $eligible = [];
        foreach ($usages as $usageId => $usage) {
            $confirmed = $usage['confirmed'];
            if ($usage['designated'] === null || $confirmed === null || $usage['allocated'] || $usage['cancelled']) {
                continue;
            }
            if (substr($confirmed->createdAt, 0, 10) >= $cutovers[$usage['userId']]['cutoverDate']) {
                continue;
            }
            $eligible[] = ['usageId' => (string) $usageId] + $usage;
        }
        // 確定の順(承認時の充当と同じ)。
        usort($eligible, fn (array $a, array $b) => [$a['confirmed']->anchorId, $a['confirmed']->subOrder] <=> [$b['confirmed']->anchorId, $b['confirmed']->subOrder]);

        /** @var array<string, array<string, array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocatedTotal: float, event: RebuiltEvent}>> $grantsByUser */
        $grantsByUser = [];
        foreach ($cutovers as $userId => $cutover) {
            foreach ($cutover['grants'] as $grantEvent) {
                $grantId = (string) $grantEvent->payload['grantId'];
                $grantsByUser[$userId][$grantId] = [
                    'grantId' => $grantId,
                    'grantedOn' => substr((string) $grantEvent->payload['grantedOn'], 0, 10),
                    'expiresOn' => substr((string) $grantEvent->payload['expiresOn'], 0, 10),
                    'grantedDays' => (float) $grantEvent->payload['grantedDays'],
                    'revoked' => false,
                    'allocatedTotal' => 0.0,
                    'event' => $grantEvent,
                ];
            }
        }

        $planner = new AllocationPlanner;
        $nextSub = [];
        $derived = [];
        foreach ($eligible as $usage) {
            $userId = $usage['userId'];
            $designated = $usage['designated'];
            $confirmed = $usage['confirmed'];
            $usedOn = substr((string) $designated->payload['usedOn'], 0, 10);
            $usedDays = (float) $designated->payload['usedDays'];
            $report['eligible_usages']++;

            $grants = $grantsByUser[$userId] ?? [];
            $plan = $planner->plan($usedOn, $usedDays, array_values(array_map(
                fn (array $g) => array_diff_key($g, ['event' => true]),
                $grants,
            )));

            $allocated = 0.0;
            foreach ($plan as $entry) {
                if ($entry['allocatedDays'] <= self::EPSILON) {
                    continue;
                }
                $grant = $grants[$entry['grantId']];
                $grantEvent = $grant['event'];
                $later = [$grantEvent->anchorId, $grantEvent->subOrder] > [$confirmed->anchorId, $confirmed->subOrder] ? $grantEvent : $confirmed;
                $meta = $later->meta;
                unset($meta['cutover']);
                $sub = $nextSub[$later->anchorId] ?? self::SUB_ORDER_BASE;
                $nextSub[$later->anchorId] = $sub + 1;

                $derived[] = new RebuiltEvent(
                    eventClass: 'paid_leave_account.usage_allocated',
                    aggregateUuid: $userId,
                    payload: [
                        'usageId' => $usage['usageId'],
                        'grantId' => $entry['grantId'],
                        'allocatedDays' => $entry['allocatedDays'],
                    ],
                    meta: $meta,
                    createdAt: $later->createdAt,
                    anchorId: $later->anchorId,
                    subOrder: $sub,
                    rebuiltFrom: $later->anchorId,
                    sourceVersion: null,
                );
                $grantsByUser[$userId][$entry['grantId']]['allocatedTotal'] += $entry['allocatedDays'];
                $allocated += $entry['allocatedDays'];
            }

            $shortfall = $usedDays - $allocated;
            if ($allocated > self::EPSILON) {
                $report['allocated_usages']++;
                $report['allocated_days'] += $allocated;
            }
            if ($shortfall > self::EPSILON) {
                $report['unallocated_usages']++;
                $report['unallocated_days'] += $shortfall;
                $report['unallocated'][] = [
                    'user_id' => $userId,
                    'usage_id' => $usage['usageId'],
                    'used_on' => $usedOn,
                    'days' => $shortfall,
                    'reason' => $allocated > self::EPSILON ? '付与の残り不足' : '有効な付与なし・付与の残り不足',
                ];
            }
        }

        return ['events' => $derived, 'report' => $report];
    }
}
