<?php

namespace App\Domain\PaidLeaveAccount\Support;

/**
 * 1件のUsage(usedOn基準)に対し、どのGrantへどれだけ充当するかを決定する副作用のない
 * Domain Service。`PaidLeaveAccountAggregate`と承認画面プレビュー(Phase 5予定)が
 * 同一ロジックを共有するために、Projection/Eloquentへは一切アクセスしない
 * 純粋関数として実装する(spec.md 論点5/6)。
 *
 * アルゴリズム(spec.md 仕様確定事項 / 依頼書§20):
 * - Step 1: usedOn時点で有効(grantedOn <= usedOn <= expiresOn)かつ未取消のGrantのうち、
 *   空き(grantedDays - allocated済み合計)があるものを expiresOn 昇順(近い順)に充当する。
 * - Step 2: Step 1で不足が残る場合、usedOnより後のgrantedOnを持つ未取消Grantのうち
 *   grantedOnが最も近い1件のみを対象に、残り不足分だけ充当を試みる(複数件へは進まない)。
 * - Step 3: それでも不足が残る場合、残りは充当せず未充当のまま残す(呼び出し側で
 *   confirm自体は失敗させない)。
 */
class AllocationPlanner
{
    /**
     * @param  array<int, array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocatedTotal: float}>  $grants
     * @return array<int, array{grantId: string, allocatedDays: float}>
     */
    public function plan(string $usedOn, float $usedDays, array $grants): array
    {
        $remaining = $usedDays;
        $plan = [];

        $validNow = array_values(array_filter(
            $grants,
            fn (array $g) => ! $g['revoked']
                && $g['grantedOn'] <= $usedOn
                && $g['expiresOn'] >= $usedOn
                && $this->availableDays($g) > 0,
        ));

        usort($validNow, fn (array $a, array $b) => $a['expiresOn'] <=> $b['expiresOn']);

        foreach ($validNow as $grant) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $this->availableDays($grant));

            if ($take <= 0) {
                continue;
            }

            $plan[] = ['grantId' => $grant['grantId'], 'allocatedDays' => $take];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $futureGrants = array_values(array_filter(
                $grants,
                fn (array $g) => ! $g['revoked'] && $g['grantedOn'] > $usedOn,
            ));

            usort($futureGrants, fn (array $a, array $b) => $a['grantedOn'] <=> $b['grantedOn']);

            $nearestFuture = $futureGrants[0] ?? null;

            if ($nearestFuture !== null) {
                $take = min($remaining, $this->availableDays($nearestFuture));

                if ($take > 0) {
                    $plan[] = ['grantId' => $nearestFuture['grantId'], 'allocatedDays' => $take];
                    $remaining -= $take;
                }
            }
        }

        // Step 3: 残余があっても失敗にはせず、未充当のまま返す(呼び出し側が判断する)。
        return $plan;
    }

    /**
     * @param  array{grantedDays: float, allocatedTotal: float}  $grant
     */
    private function availableDays(array $grant): float
    {
        return $grant['grantedDays'] - $grant['allocatedTotal'];
    }
}
