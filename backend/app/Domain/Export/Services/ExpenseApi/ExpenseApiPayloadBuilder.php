<?php

namespace App\Domain\Export\Services\ExpenseApi;

use App\Models\ExpenseClaim;

/**
 * 経費API連携(フェーズ3)のペイロード組み立ての共通インターフェース。
 * AttendanceApiPayloadBuilderと同じ発想で、連携先(freee/moneyforward)ごとに
 * ペイロード構造をBuilder実装へ分ける。docs/30-usecases-expense.md UC-X012参照。
 */
interface ExpenseApiPayloadBuilder
{
    /** 連携先を一意に識別するキー(ExternalIntegrationConnection::providerと一致させる)。 */
    public function key(): string;

    /**
     * @return array<string, mixed>
     */
    public function build(ExpenseClaim $claim, string $externalEmployeeCode): array;
}
