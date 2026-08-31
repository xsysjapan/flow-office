<?php

namespace App\Domain\Asset\Reactors;

use App\Domain\Asset\Events\AssetLoaned;
use App\Models\AssetLoanRequest;
use App\Models\AssetLoanRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * approval方式で承認済み申請に基づいて貸与された場合(loanRequestIdあり)、対応する
 * asset_loan_requestsをlent表示に更新する(spec 論点1・「状態遷移」)。
 */
class AssetLoanRequestOnAssetLoanedReactor extends Reactor
{
    public function onAssetLoaned(AssetLoaned $event): void
    {
        if ($event->loanRequestId === null) {
            return;
        }

        $loanRequest = AssetLoanRequest::query()->find($event->loanRequestId);
        if ($loanRequest === null) {
            return;
        }

        $loanRequest->update([
            'status' => AssetLoanRequestStatus::LENT,
            'lent_at' => $event->loanedAt,
        ]);
    }
}
