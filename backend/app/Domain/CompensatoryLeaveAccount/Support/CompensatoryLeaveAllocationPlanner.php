<?php

namespace App\Domain\CompensatoryLeaveAccount\Support;

/**
 * 代休の消化記録1件(利用日基準)を、どの付与へどれだけ充当するかを決定する副作用のない純粋クラス。
 * Projection/Eloquentへは一切アクセスしない。
 *
 * 現行ルール(移植元: App\Domain\CompensatoryLeave\Handlers\ApproveCompensatoryLeaveRequestHandler::planConsumption):
 * - 対象: 失効していない(expiresOn が null、または expiresOn >= 利用日。失効日当日は有効)かつ
 *   残数(available)が0より大きいもの。
 * - 順序: 失効日の近い順。無期限(expiresOn が null)は最後。同じ順位は入力(登録)順。
 * - 充当量: 残りの必要量と付与の残数の小さい方を充当し、残りが0になるまで続ける。
 * - 不足分は計画に含めない(呼び出し側が不足を判定する)。
 *
 * 日数・分の別は呼び出し側(CompensatoryLeaveAccountAggregate)が決める。ここでは単位に依存しない
 * 「残数(available)」と「必要量(requiredAmount)」だけを扱う。
 * 取消済み・確定前の付与は呼び出し側が入力から除外して渡す。
 */
class CompensatoryLeaveAllocationPlanner
{
    /**
     * @param  array<int, array{grantId: string, expiresOn: ?string, available: float}>  $grants
     * @return array<int, array{grantId: string, allocatedAmount: float}>
     */
    public function plan(string $usedOn, float $requiredAmount, array $grants): array
    {
        $remaining = $requiredAmount;
        $plan = [];

        $candidates = array_values(array_filter(
            $grants,
            fn (array $grant) => ($grant['expiresOn'] === null || $grant['expiresOn'] >= $usedOn)
                && $grant['available'] > 0,
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

            $take = min($remaining, $grant['available']);

            $plan[] = ['grantId' => $grant['grantId'], 'allocatedAmount' => $take];
            $remaining -= $take;
        }

        return $plan;
    }
}
