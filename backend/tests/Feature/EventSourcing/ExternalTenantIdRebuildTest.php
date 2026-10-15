<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\EventRestorabilityChecker;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\Reconcile\SeededDataCurrent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 論点19 決定9: 旧イベントへの externalTenantId の補完を作り直しに組み込んだ結果が、現行のイベントクラスへ復元できること。
 * 補完の規則の判定は tests/Unit/EventSourcing/Rebuild/ExternalTenantIdRebuilderTest.php にある。
 */
class ExternalTenantIdRebuildTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '11111111-1111-4111-8111-111111111111';

    private const AT = '2026-08-04 08:43:09';

    public function test_rebuild_fills_the_old_sso_event_and_the_result_restores_to_the_current_classes(): void
    {
        $rows = [
            new StoredEventRow(1, self::USER, 1, 'user.created_from_sso_login', ['entraUserId' => 'entra-old-1', 'name' => 'Old', 'email' => 'old@example.test'], ['aggregate-root-uuid' => self::USER, 'created-at' => self::AT], self::AT),
        ];
        $identities = [[
            'id' => 1, 'user_id' => self::USER, 'provider' => 'MICROSOFT_ENTRA', 'external_subject_id' => 'entra-old-1',
            'external_tenant_id' => 'tenant-A', 'external_code' => null, 'email' => 'old@example.test', 'status' => 'active',
            'linked_at' => self::AT, 'last_synced_at' => self::AT, 'created_at' => self::AT, 'updated_at' => self::AT,
        ]];

        $result = app(EventStoreRebuilder::class)->rebuild($rows, [], [], new SeededDataCurrent(externalIdentities: $identities, m365TenantId: 'tenant-A'));

        $this->assertTrue($result->ok, json_encode($result->unsupported, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $result->report['external_tenant_id']['filled_total']);
        $this->assertSame('tenant-A', $result->report['external_tenant_id']['tenant']);

        $rebuilt = array_values(array_filter($result->rows, fn (array $row) => $row['event_class'] === 'user.created_from_sso_login'));
        $this->assertCount(1, $rebuilt);
        $this->assertSame('tenant-A', $rebuilt[0]['event_properties']['externalTenantId']);
        $this->assertSame([], (new EventRestorabilityChecker)->checkRows($result->rows));
    }
}
