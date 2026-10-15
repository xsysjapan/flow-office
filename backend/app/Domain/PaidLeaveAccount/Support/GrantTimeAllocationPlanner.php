<?php

namespace App\Domain\PaidLeaveAccount\Support;

/**
 * 付与時の未充当の充当(論点19 決定10-1)の業務ルール。副作用のない純粋関数。
 *
 * 付与1件の残りの範囲で、未充当の消化記録へ確定の古い順(confirmOrder昇順)に充当する。
 * 付与の有効期間と消化記録の対象日の関係は問わない。残りが尽きたら以降は充当しない。
 * 未充当が無い(remainingDays <= 0)消化記録は対象外。
 */
class GrantTimeAllocationPlanner
{
    private const EPSILON = 0.000001;

    /**
     * @param  float  $grantRemaining  付与の残り(付与日数 − 有効な充当の合計)
     * @param  list<array{usageId: string, remainingDays: float, confirmOrder: int}>  $pendingUsages  確定済み・未取消の消化記録と、その未充当の日数
     * @return list<array{usageId: string, allocatedDays: float}>
     */
    public function plan(float $grantRemaining, array $pendingUsages): array
    {
        usort($pendingUsages, fn (array $a, array $b) => $a['confirmOrder'] <=> $b['confirmOrder']);

        $plan = [];
        $remaining = $grantRemaining;
        foreach ($pendingUsages as $usage) {
            if ($remaining <= self::EPSILON) {
                break;
            }
            if ($usage['remainingDays'] <= self::EPSILON) {
                continue;
            }
            $take = min($remaining, $usage['remainingDays']);
            $plan[] = ['usageId' => $usage['usageId'], 'allocatedDays' => $take];
            $remaining -= $take;
        }

        return $plan;
    }
}
