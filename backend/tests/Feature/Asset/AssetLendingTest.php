<?php

namespace Tests\Feature\Asset;

use App\Domain\Asset\Commands\ChangeAssetLendingMethod;
use App\Domain\Asset\Commands\CompleteAssetRepair;
use App\Domain\Asset\Commands\DisposeAsset;
use App\Domain\Asset\Commands\LendAsset;
use App\Domain\Asset\Commands\RecoverAssetFromLost;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\Asset\Commands\ReportAssetLost;
use App\Domain\Asset\Commands\ReturnAsset;
use App\Domain\Asset\Commands\StartAssetRepair;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetLendingMethod;
use App\Models\AssetLendingStatus;
use App\Models\AssetLoan;
use App\Models\AssetManagementType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 貸出品(management_type=lending)の貸与・返却・修理・紛失/発見・廃棄。
 * spec「状態遷移」「貸出方式(lending_method)とLendAsset呼び出し条件」参照。
 */
class AssetLendingTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function registerLendingAsset(User $backoffice, string $lendingMethod = AssetLendingMethod::BACKOFFICE, ?string $defaultLocationText = null): Asset
    {
        return $this->bus()->dispatch(new RegisterAsset(
            assetNo: 'EQ-'.uniqid(),
            name: 'ノートPC',
            category: 'PC',
            serialNumber: 'SN-001',
            managementType: AssetManagementType::LENDING,
            lendingMethod: $lendingMethod,
            defaultLocationText: $defaultLocationText,
            notes: null,
            registeredByUserId: $backoffice->id,
        ));
    }

    public function test_available_asset_can_be_lent(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $result = $this->bus()->dispatch(new LendAsset(
            assetId: $asset->id,
            borrowerUserId: $borrower->id,
            lentByUserId: $backoffice->id,
        ));

        $this->assertSame(AssetLendingStatus::LOANED, $result->lending_status);
        $this->assertNotNull($result->current_loan_id);

        $loan = AssetLoan::query()->findOrFail($result->current_loan_id);
        $this->assertSame($asset->id, $loan->asset_id);
        $this->assertSame($borrower->id, $loan->user_id);
        $this->assertNull($loan->returned_at);
    }

    public function test_a_loaned_asset_cannot_be_lent_again(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));
    }

    public function test_an_asset_under_repair_cannot_be_lent(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $this->bus()->dispatch(new StartAssetRepair($asset->id, $backoffice->id));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));
    }

    public function test_a_lost_asset_cannot_be_lent(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $this->bus()->dispatch(new ReportAssetLost($asset->id, $backoffice->id));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));
    }

    public function test_a_disposed_asset_cannot_be_lent(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $this->bus()->dispatch(new DisposeAsset($asset->id, $backoffice->id));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));
    }

    public function test_self_service_asset_can_be_lent_directly_to_the_borrower_themselves(): void
    {
        $backoffice = User::factory()->create();
        $employee = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice, AssetLendingMethod::SELF_SERVICE, '3階ロッカーA');

        $result = $this->bus()->dispatch(new LendAsset(
            assetId: $asset->id,
            borrowerUserId: $employee->id,
            lentByUserId: $employee->id,
        ));

        $loan = AssetLoan::query()->findOrFail($result->current_loan_id);
        $this->assertSame($employee->id, $loan->user_id);
        $this->assertSame($employee->id, $loan->loaned_by_user_id);
    }

    public function test_self_service_asset_cannot_be_registered_without_a_default_location(): void
    {
        $backoffice = User::factory()->create();

        $this->expectException(DomainRuleException::class);
        $this->registerLendingAsset($backoffice, AssetLendingMethod::SELF_SERVICE, null);
    }

    public function test_changing_lending_method_to_self_service_requires_a_default_location(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice, AssetLendingMethod::BACKOFFICE, null);

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new ChangeAssetLendingMethod($asset->id, AssetLendingMethod::SELF_SERVICE, $backoffice->id));
    }

    public function test_changing_lending_method_to_self_service_succeeds_once_default_location_is_set(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice, AssetLendingMethod::BACKOFFICE, '2階倉庫');

        $result = $this->bus()->dispatch(new ChangeAssetLendingMethod($asset->id, AssetLendingMethod::SELF_SERVICE, $backoffice->id));

        $this->assertSame(AssetLendingMethod::SELF_SERVICE, $result->lending_method);
    }

    public function test_an_active_loan_can_be_returned(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);
        $loaned = $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));

        $result = $this->bus()->dispatch(new ReturnAsset(
            assetId: $asset->id,
            loanId: $loaned->current_loan_id,
            returnedByUserId: $backoffice->id,
            returnNote: '問題なし',
        ));

        $this->assertSame(AssetLendingStatus::AVAILABLE, $result->lending_status);
        $this->assertNull($result->current_loan_id);

        $loan = AssetLoan::query()->findOrFail($loaned->current_loan_id);
        $this->assertNotNull($loan->returned_at);
        $this->assertSame($backoffice->id, $loan->returned_by_user_id);
    }

    public function test_return_succeeds_even_without_a_default_location_for_backoffice_managed_assets(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice, AssetLendingMethod::BACKOFFICE, null);
        $loaned = $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));

        $result = $this->bus()->dispatch(new ReturnAsset($asset->id, $loaned->current_loan_id, $backoffice->id));

        $this->assertSame(AssetLendingStatus::AVAILABLE, $result->lending_status);
    }

    public function test_returning_a_loan_id_that_is_not_the_currently_active_loan_is_rejected(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);
        $loaned = $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));
        $this->bus()->dispatch(new ReturnAsset($asset->id, $loaned->current_loan_id, $backoffice->id));

        $this->expectException(DomainRuleException::class);
        // 既に返却済みの同じloanIdをもう一度返却しようとする。
        $this->bus()->dispatch(new ReturnAsset($asset->id, $loaned->current_loan_id, $backoffice->id));
    }

    public function test_repair_lifecycle_returns_asset_to_available(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $repairing = $this->bus()->dispatch(new StartAssetRepair($asset->id, $backoffice->id, '画面割れ'));
        $this->assertSame(AssetLendingStatus::REPAIR, $repairing->lending_status);

        $repaired = $this->bus()->dispatch(new CompleteAssetRepair($asset->id, $backoffice->id));
        $this->assertSame(AssetLendingStatus::AVAILABLE, $repaired->lending_status);
    }

    public function test_lost_and_recovered_asset_that_was_not_loaned_returns_to_available(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $this->bus()->dispatch(new ReportAssetLost($asset->id, $backoffice->id));
        $recovered = $this->bus()->dispatch(new RecoverAssetFromLost($asset->id, $backoffice->id));

        $this->assertSame(AssetLendingStatus::AVAILABLE, $recovered->lending_status);
    }

    public function test_lost_asset_that_was_loaned_keeps_borrower_information_and_recovers_to_loaned(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);
        $loaned = $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));

        $lost = $this->bus()->dispatch(new ReportAssetLost($asset->id, $backoffice->id));
        $this->assertSame(AssetLendingStatus::LOST, $lost->lending_status);
        $this->assertSame($loaned->current_loan_id, $lost->current_loan_id);

        $recovered = $this->bus()->dispatch(new RecoverAssetFromLost($asset->id, $backoffice->id));
        $this->assertSame(AssetLendingStatus::LOANED, $recovered->lending_status);
        $this->assertSame($loaned->current_loan_id, $recovered->current_loan_id);
    }

    public function test_loaned_asset_cannot_be_disposed_directly(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);
        $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new DisposeAsset($asset->id, $backoffice->id));
    }

    public function test_available_asset_can_be_disposed(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $result = $this->bus()->dispatch(new DisposeAsset($asset->id, $backoffice->id, '経年劣化'));

        $this->assertSame(AssetLendingStatus::DISPOSED, $result->lending_status);
    }
}
