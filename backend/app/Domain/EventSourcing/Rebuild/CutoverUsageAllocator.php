<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\PaidLeaveAccount\Support\AllocationPlanner;

/**
 * 対象日がcutover前で、充当(旧 paid_leave.used)が無いまま確定した有給の消化記録を、その利用者の付与に充当する
 * usage_allocated を導出する(論点19 決定9-3a の改訂、assets/event-rebuild-mapping.md 2.3)。
 *
 * - 対象: 口座集約(userId)の usage_confirmed のうち、消化記録の対象日(usage_designated の usedOn)が「cutoverの日付」より前、
 *   usage_allocated・usage_cancelled が無いもの。cutoverの日付は、その利用者に migrated があればその cutoverDate、
 *   無ければ全利用者の cutoverDate の最小値。migrated が全く無ければ何もしない。
 * - 充当先: その利用者の全ての付与(作り直し後の grant_created。変換されずにそのまま写る行を含む。carried_over に限らない)。取消(grant_revoked)済みは除く。
 *   付与日数は grant_amount_changed があれば最終の日数。既存の有効な充当(usage_allocated の合計 − usage_allocation_released の合計)を
 *   付与ごとに差し引いてから AllocationPlanner に渡す(付与の残りを超えない)。
 * - 充当の順序・単位: 現行の口座集約の承認時の充当と同じ(確定の順に、AllocationPlanner で対象日に有効な付与の
 *   expiresOn 昇順、足りなければ直近の将来の付与1件。足りない分は未充当のまま。論点17)。
 * - 置く位置: 充当先の付与の grant_created と、その消化の usage_confirmed のうち後ろの直後(subOrder で順序)。
 *   発生日時・meta は置き場所の行から引き継ぐ。
 */
final class CutoverUsageAllocator
{
    /** 置き場所の行(anchor)の中で、元の行より後ろに置くための subOrder の開始値。 */
    public const SUB_ORDER_BASE = 100;

    private const EPSILON = 0.000001;

    /**
     * @param  list<RebuiltEvent>  $events  作り直し後の口座のイベント(変換後のものと、変換されずにそのまま写る行の両方)
     * @param  array<string, string>  $cutoverDates  利用者ID => その利用者の cutover(migrated)の日付(migrated のある利用者のみ)
     * @return array{events: list<RebuiltEvent>, report: array<string, mixed>}
     */
    public function allocate(array $events, array $cutoverDates): array
    {
        $report = [
            'min_cutover_date' => $cutoverDates === [] ? null : min($cutoverDates),
            'eligible_usages' => 0,
            'allocated_usages' => 0,
            'allocated_days' => 0.0,
            'unallocated_usages' => 0,
            'unallocated_days' => 0.0,
            'unallocated' => [],
            'usages' => [],
        ];
        if ($cutoverDates === []) {
            return ['events' => [], 'report' => $report];
        }
        $minCutover = min($cutoverDates);

        /** @var array<string, array{userId: string, designated: ?RebuiltEvent, confirmed: ?RebuiltEvent, allocated: bool, cancelled: bool}> $usages */
        $usages = [];
        /** @var array<string, array<string, array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocatedTotal: float, event: RebuiltEvent}>> $grantsByUser */
        $grantsByUser = [];
        $amountChanged = [];
        $revoked = [];
        /** @var list<array{userId: string, grantId: string, days: float}> $existingAllocations 付与ごとの有効な充当の増減(充当は正、解除は負) */
        $existingAllocations = [];
        foreach ($events as $event) {
            $userId = $event->aggregateUuid;
            if ($userId === null) {
                continue;
            }
            switch ($event->eventClass) {
                case 'paid_leave_account.grant_created':
                    $grantId = (string) $event->payload['grantId'];
                    $grantsByUser[$userId][$grantId] = [
                        'grantId' => $grantId,
                        'grantedOn' => substr((string) $event->payload['grantedOn'], 0, 10),
                        'expiresOn' => substr((string) $event->payload['expiresOn'], 0, 10),
                        'grantedDays' => (float) $event->payload['grantedDays'],
                        'revoked' => false,
                        'allocatedTotal' => 0.0,
                        'event' => $event,
                    ];
                    break;
                case 'paid_leave_account.grant_revoked':
                    $revoked[$userId][(string) $event->payload['grantId']] = true;
                    break;
                case 'paid_leave_account.grant_amount_changed':
                    $key = $userId.'|'.$event->payload['grantId'];
                    if (! isset($amountChanged[$key]) || [$amountChanged[$key]->anchorId, $amountChanged[$key]->subOrder] <= [$event->anchorId, $event->subOrder]) {
                        $amountChanged[$key] = $event;
                    }
                    break;
                case 'paid_leave_account.usage_designated':
                case 'paid_leave_account.usage_confirmed':
                case 'paid_leave_account.usage_allocated':
                case 'paid_leave_account.usage_cancelled':
                    $usageId = $event->payload['usageId'] ?? null;
                    if ($usageId === null) {
                        break;
                    }
                    $usageId = (string) $usageId;
                    $usages[$usageId] ??= ['userId' => $userId, 'designated' => null, 'confirmed' => null, 'allocated' => false, 'cancelled' => false];
                    match ($event->eventClass) {
                        'paid_leave_account.usage_designated' => $usages[$usageId]['designated'] = $event,
                        'paid_leave_account.usage_confirmed' => $usages[$usageId]['confirmed'] = $event,
                        'paid_leave_account.usage_allocated' => $usages[$usageId]['allocated'] = true,
                        'paid_leave_account.usage_cancelled' => $usages[$usageId]['cancelled'] = true,
                    };
                    if ($event->eventClass === 'paid_leave_account.usage_allocated') {
                        $existingAllocations[] = ['userId' => $userId, 'grantId' => (string) $event->payload['grantId'], 'days' => (float) $event->payload['allocatedDays']];
                    }
                    break;
                case 'paid_leave_account.usage_allocation_released':
                    $existingAllocations[] = ['userId' => $userId, 'grantId' => (string) $event->payload['grantId'], 'days' => -(float) $event->payload['releasedDays']];
                    break;
                default:
                    break;
            }
        }

        foreach ($grantsByUser as $userId => $grants) {
            foreach ($grants as $grantId => $grant) {
                if (isset($revoked[$userId][$grantId])) {
                    $grantsByUser[$userId][$grantId]['revoked'] = true;
                }
                if (isset($amountChanged[$userId.'|'.$grantId])) {
                    $grantsByUser[$userId][$grantId]['grantedDays'] = (float) $amountChanged[$userId.'|'.$grantId]->payload['newGrantedDays'];
                }
            }
        }
        // 既存の有効な充当(usage_allocated の合計 − usage_allocation_released の合計。PaidLeaveBalanceProjector と同じ)を差し引く。
        foreach ($existingAllocations as $allocation) {
            if (! isset($grantsByUser[$allocation['userId']][$allocation['grantId']])) {
                continue;
            }
            $grantsByUser[$allocation['userId']][$allocation['grantId']]['allocatedTotal'] += $allocation['days'];
        }

        $eligible = [];
        foreach ($usages as $usageId => $usage) {
            $confirmed = $usage['confirmed'];
            if ($usage['designated'] === null || $confirmed === null || $usage['allocated'] || $usage['cancelled']) {
                continue;
            }
            $userCutover = $cutoverDates[$usage['userId']] ?? null;
            $cutoverDate = $userCutover ?? $minCutover;
            // 対象は消化記録の対象日(usage_designated の usedOn)で選ぶ。確定の日付では選ばない(cutoverの経路は確定がcutover以降に記録されるため)。
            if (substr((string) $usage['designated']->payload['usedOn'], 0, 10) >= $cutoverDate) {
                continue;
            }
            $eligible[] = ['usageId' => (string) $usageId, 'cutoverDate' => $cutoverDate, 'cutoverBasis' => $userCutover !== null ? 'migrated' : 'min'] + $usage;
        }
        // 確定の順(承認時の充当と同じ)。
        usort($eligible, fn (array $a, array $b) => [$a['confirmed']->anchorId, $a['confirmed']->subOrder] <=> [$b['confirmed']->anchorId, $b['confirmed']->subOrder]);

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
            // 残りの無い付与(日数0・使い切り)は候補から外す。AllocationPlanner は「直近の将来の付与」を1件だけ見るので、
            // 残りの無い付与が直近だと、その先の付与へ充当されなくなる。
            $candidates = array_filter($grants, fn (array $g) => $g['grantedDays'] - $g['allocatedTotal'] > self::EPSILON);
            $plan = $planner->plan($usedOn, $usedDays, array_values(array_map(
                fn (array $g) => array_diff_key($g, ['event' => true]),
                $candidates,
            )));

            $allocated = 0.0;
            $allocatedTo = [];
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
                $allocatedTo[] = ['grant_id' => $entry['grantId'], 'days' => $entry['allocatedDays']];
            }

            $shortfall = $usedDays - $allocated;
            $reason = $shortfall > self::EPSILON ? ($allocated > self::EPSILON ? '付与の残り不足' : '有効な付与なし・付与の残り不足') : null;
            $report['usages'][] = [
                'usage_id' => $usage['usageId'],
                'user_id' => $userId,
                'used_on' => $usedOn,
                'days' => $usedDays,
                'allocations' => $allocatedTo,
                'unallocated_days' => max(0.0, $shortfall),
                'reason' => $reason,
                'cutover_date' => $usage['cutoverDate'],
                'cutover_basis' => $usage['cutoverBasis'],
            ];
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
                    'reason' => $reason,
                ];
            }
        }

        return ['events' => $derived, 'report' => $report];
    }
}
