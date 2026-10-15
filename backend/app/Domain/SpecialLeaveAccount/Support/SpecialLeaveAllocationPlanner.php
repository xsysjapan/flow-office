<?php

namespace App\Domain\SpecialLeaveAccount\Support;

/**
 * 特別休暇の消化記録1件(利用日基準)を、どの付与へどれだけ充当するかを決定する副作用のない
 * 純粋クラス。Projection/Eloquentへは一切アクセスしない。
 *
 * 現行ルール(移植元: App\Domain\SpecialLeave\Handlers\ApproveSpecialLeaveRequestHandler::planConsumption):
 * - 対象: 同じ種類の付与のうち、失効していない(expiresOn が null、または expiresOn >= 利用日。
 *   失効日当日は有効)かつ残数が0より大きいもの。
 * - 順序: 失効日の近い順。無期限(expiresOn が null)は最後。同じ順位は入力(登録)順。
 * - 充当量: 残りの必要量と付与の残数の小さい方を充当し、残りが0になるまで続ける。
 * - 不足分は計画に含めない(呼び出し側が不足を判定する)。
 *
 * 呼び出し側(SpecialLeaveAccountAggregate)が、取消済みの付与を入力から除外して渡す。
 */
class SpecialLeaveAllocationPlanner
{
    /**
     * @param  array<int, array{grantId: string, expiresOn: ?string, grantedDays: float, allocatedTotal: float, revoked: bool}>  $grants
     * @return array<int, array{grantId: string, allocatedDays: float}>
     */
    public function plan(string $usedOn, float $usedDays, array $grants): array
    {
        $remaining = $usedDays;
        $plan = [];

        $candidates = array_values(array_filter(
            $grants,
            fn (array $grant) => ! $grant['revoked']
                && ($grant['expiresOn'] === null || $grant['expiresOn'] >= $usedOn)
                && $this->availableDays($grant) > 0,
        ));

        // 無期限を最後にし、それ以外は失効日の昇順。PHP 8.0以降のusortは安定ソートのため同順位は入力順が保たれる。
        usort(
            $candidates,
            fn (array $a, array $b) => [$a['expiresOn'] === null, $a['expiresOn'] ?? ''] <=> [$b['expiresOn'] === null, $b['expiresOn'] ?? ''],
        );

        foreach ($candidates as $grant) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $this->availableDays($grant));

            $plan[] = ['grantId' => $grant['grantId'], 'allocatedDays' => $take];
            $remaining -= $take;
        }

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
