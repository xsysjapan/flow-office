<?php

namespace Tests\Feature\Asset;

use App\Domain\Asset\Commands\DeleteAsset;
use App\Domain\Asset\Commands\LendAsset;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\Asset\Commands\ReturnAsset;
use App\Domain\Asset\Commands\StartAssetRepair;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetManagementType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 削除(DeleteAsset)。EventStore(stored_events)は正本として残し、Read Model(assets)からは
 * 物理削除する(spec「削除 vs 廃棄」)。履歴があっても削除でき、貸出中/修理中/進行中の
 * 業務がある場合は削除できない。
 */
class AssetDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function registerLendingAsset(User $backoffice): Asset
    {
        return $this->bus()->dispatch(new RegisterAsset(
            assetNo: 'EQ-'.uniqid(),
            name: 'ノートPC',
            category: 'PC',
            serialNumber: null,
            managementType: AssetManagementType::LENDING,
            lendingMethod: 'backoffice',
            defaultLocationText: null,
            notes: null,
            registeredByUserId: $backoffice->id,
        ));
    }

    public function test_an_asset_with_loan_history_can_still_be_deleted(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);
        $loaned = $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));
        $this->bus()->dispatch(new ReturnAsset($asset->id, $loaned->current_loan_id, $backoffice->id));

        $this->bus()->dispatch(new DeleteAsset($asset->id, $backoffice->id));

        $this->assertNull(Asset::query()->find($asset->id));
    }

    public function test_deleting_an_asset_records_an_asset_deleted_event(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $this->bus()->dispatch(new DeleteAsset($asset->id, $backoffice->id));

        $recorded = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $asset->id)
            ->where('event_class', 'asset.deleted')
            ->exists();

        $this->assertTrue($recorded);
    }

    public function test_deleting_an_asset_removes_it_from_the_read_model(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);

        $this->bus()->dispatch(new DeleteAsset($asset->id, $backoffice->id));

        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
    }

    public function test_a_loaned_asset_cannot_be_deleted(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);
        $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $backoffice->id));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new DeleteAsset($asset->id, $backoffice->id));
    }

    public function test_an_asset_under_repair_cannot_be_deleted(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerLendingAsset($backoffice);
        $this->bus()->dispatch(new StartAssetRepair($asset->id, $backoffice->id));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new DeleteAsset($asset->id, $backoffice->id));
    }
}
