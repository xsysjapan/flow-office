<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Commands\ApplyAssetBulkOperation;
use App\Domain\Asset\Commands\LendAsset;
use App\Domain\Asset\Commands\RelocateAsset;
use App\Domain\Asset\Commands\ReturnAsset;
use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetLendingMethod;
use App\Models\AssetLoanRequest;
use App\Models\AssetLoanRequestStatus;

/**
 * 備品の一括操作。各件を1件のCommand(`LendAsset`・`ReturnAsset`・`RelocateAsset`)のdispatchで行い、
 * 1件でも失敗したら全体を戻す(`BulkOperationRejected`)。他人の貸出の返却の認可は`ReturnAssetHandler`が行う。
 *
 * @implements CommandHandler<ApplyAssetBulkOperation>
 */
class ApplyAssetBulkOperationHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ApplyAssetBulkOperation);

        return BulkItemRunner::run($command->assetIds, function ($assetId) use ($command) {
            $asset = Asset::query()->findOrFail($assetId);
            $this->applyOperation($command, $asset);
        });
    }

    private function applyOperation(ApplyAssetBulkOperation $command, Asset $asset): void
    {
        $actingUserId = $command->actingUserId;

        switch ($command->operation) {
            case 'self_loan':
                if ($asset->lending_method !== AssetLendingMethod::SELF_SERVICE) {
                    throw new DomainRuleException('セルフサービス方式の備品のみセルフ貸出できます。');
                }
                $this->commandBus->dispatch(new LendAsset(
                    assetId: $asset->id,
                    borrowerUserId: $actingUserId,
                    lentByUserId: $actingUserId,
                    expectedReturnAt: $command->expectedReturnAt,
                ));

                return;

            case 'self_return':
                if ($asset->current_loan_id === null || $asset->current_loan_id !== $this->currentLoanIdFor($asset, $actingUserId)) {
                    throw new DomainRuleException('自分が借用中の備品のみ返却できます。');
                }
                $this->commandBus->dispatch(new ReturnAsset(
                    assetId: $asset->id,
                    loanId: $asset->current_loan_id,
                    returnedByUserId: $actingUserId,
                    returnNote: $command->returnNote,
                ));

                return;

            case 'backoffice_lend':
                $borrowerUserId = $command->borrowerUserId;
                if ($borrowerUserId === null) {
                    throw new DomainRuleException('借用者を指定してください。');
                }

                $loanRequestId = null;
                if ($asset->lending_method === AssetLendingMethod::APPROVAL) {
                    $loanRequestId = AssetLoanRequest::query()
                        ->where('asset_id', $asset->id)
                        ->where('applicant_user_id', $borrowerUserId)
                        ->where('status', AssetLoanRequestStatus::APPROVED)
                        ->latest('approved_at')
                        ->value('id');
                }

                $this->commandBus->dispatch(new LendAsset(
                    assetId: $asset->id,
                    borrowerUserId: $borrowerUserId,
                    lentByUserId: $actingUserId,
                    expectedReturnAt: $command->expectedReturnAt,
                    loanRequestId: $loanRequestId,
                ));

                return;

            case 'return':
                if ($asset->current_loan_id === null) {
                    throw new DomainRuleException('現在アクティブな貸出がありません。');
                }
                $this->commandBus->dispatch(new ReturnAsset(
                    assetId: $asset->id,
                    loanId: $asset->current_loan_id,
                    returnedByUserId: $actingUserId,
                    returnNote: $command->returnNote,
                ));

                return;

            case 'relocate':
                if ($command->locationText === null) {
                    throw new DomainRuleException('移設先を指定してください。');
                }
                $this->commandBus->dispatch(new RelocateAsset(
                    assetId: $asset->id,
                    locationText: $command->locationText,
                    relocatedByUserId: $actingUserId,
                ));

                return;

            default:
                throw new DomainRuleException('未対応の操作です。');
        }
    }

    private function currentLoanIdFor(Asset $asset, string $userId): ?string
    {
        return $asset->loans()
            ->whereNull('returned_at')
            ->where('user_id', $userId)
            ->value('id');
    }
}
