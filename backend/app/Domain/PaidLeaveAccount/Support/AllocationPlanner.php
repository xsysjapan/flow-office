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
 * - Step 1の同点は 付与日→order(記録の順)の古い順。
 * - Step 2: Step 1で不足が残る場合、usedOnより後のgrantedOnを持つ未取消Grantのうち
 *   grantedOnが最も近い付与日の付与だけを対象に、orderの順に残り不足分を充当する
 *   (1件目の残りが足りなければ同じ日の次の付与に続ける。別の付与日へは進まない)。
 * - Step 3: それでも不足が残る場合、残りは充当せず未充当のまま残す(呼び出し側で
 *   confirm自体は失敗させない)。
 */
class AllocationPlanner
{
    /**
     * @param  array<int, array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, revoked: bool, allocatedTotal: float, order?: int}>  $grants
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

        // 有効期限→付与日→記録の順(order)の古い順。
        usort($validNow, fn (array $a, array $b) => $a['expiresOn'] <=> $b['expiresOn']
            ?: $a['grantedOn'] <=> $b['grantedOn']
            ?: ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

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

            usort($futureGrants, fn (array $a, array $b) => $a['grantedOn'] <=> $b['grantedOn']
                ?: ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

            // 最も近い将来の付与日の付与だけを対象に、order順に続けて充当する(1件目で足りなければ同じ日の次へ)。
            $nearestDate = $futureGrants[0]['grantedOn'] ?? null;

            foreach ($futureGrants as $grant) {
                if ($remaining <= 0 || $grant['grantedOn'] !== $nearestDate) {
                    break;
                }

                $take = min($remaining, $this->availableDays($grant));

                if ($take > 0) {
                    $plan[] = ['grantId' => $grant['grantId'], 'allocatedDays' => $take];
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
