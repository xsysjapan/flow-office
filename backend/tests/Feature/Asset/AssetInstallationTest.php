<?php

namespace Tests\Feature\Asset;

use App\Domain\Asset\Commands\InstallAsset;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\Asset\Commands\RelocateAsset;
use App\Domain\Asset\Commands\RemoveAssetFromInstallation;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetManagementType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 設置品(management_type=installation)の設置・移設・撤去。 */
class AssetInstallationTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function registerInstallationAsset(User $backoffice): Asset
    {
        return $this->bus()->dispatch(new RegisterAsset(
            assetNo: 'EQ-'.uniqid(),
            name: '会議室モニター',
            category: 'ディスプレイ',
            serialNumber: null,
            managementType: AssetManagementType::INSTALLATION,
            lendingMethod: null,
            defaultLocationText: null,
            notes: null,
            registeredByUserId: $backoffice->id,
        ));
    }

    public function test_a_stored_asset_can_be_installed(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerInstallationAsset($backoffice);

        $result = $this->bus()->dispatch(new InstallAsset($asset->id, '第1会議室', $backoffice->id));

        $this->assertSame(AssetInstallationStatus::INSTALLED, $result->installation_status);
        $placement = $asset->fresh()->currentPlacement();
        $this->assertSame('第1会議室', $placement->location_text);
        $this->assertNull($placement->ended_at);
    }

    public function test_an_installed_asset_can_be_relocated(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerInstallationAsset($backoffice);
        $this->bus()->dispatch(new InstallAsset($asset->id, '第1会議室', $backoffice->id));

        $result = $this->bus()->dispatch(new RelocateAsset($asset->id, '第2会議室', $backoffice->id));

        $this->assertSame(AssetInstallationStatus::INSTALLED, $result->installation_status);
        $placement = $asset->fresh()->currentPlacement();
        $this->assertSame('第2会議室', $placement->location_text);
        $this->assertSame(2, $asset->placements()->count());
    }

    public function test_a_stored_asset_cannot_be_relocated(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerInstallationAsset($backoffice);

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new RelocateAsset($asset->id, '第2会議室', $backoffice->id));
    }

    public function test_an_installed_asset_can_be_removed_from_installation(): void
    {
        $backoffice = User::factory()->create();
        $asset = $this->registerInstallationAsset($backoffice);
        $this->bus()->dispatch(new InstallAsset($asset->id, '第1会議室', $backoffice->id));

        $result = $this->bus()->dispatch(new RemoveAssetFromInstallation($asset->id, $backoffice->id));

        $this->assertSame(AssetInstallationStatus::STORED, $result->installation_status);
        $this->assertNull($asset->fresh()->currentPlacement());
    }
}
