<?php

namespace Tests\Feature\Export;

use App\Models\ExternalIntegrationConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 外部連携(freee/マネーフォワード)設定の管理API。docs/33-usecases-attendance-external-api.md,
 * docs/30-usecases-expense.md参照。
 */
class ExternalIntegrationConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_admin_can_create_an_oauth2_connection_and_secrets_are_masked_on_index(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/admin/external-integration-connections', [
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'name' => 'freee本社事業所',
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'client_id' => 'client-id-1234',
            'client_secret' => 'client-secret-5678',
            'enabled' => true,
            'custom_settings' => ['closing_day' => 20],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('has_client_secret', true);
        $response->assertJsonMissing(['client_secret' => 'client-secret-5678']);
        $id = $response->json('id');

        $connection = ExternalIntegrationConnection::query()->findOrFail($id);
        $this->assertSame('client-secret-5678', $connection->client_secret);
        $this->assertSame(['closing_day' => 20], $connection->custom_settings);
        $this->assertTrue($connection->enabled);

        $index = $this->actingAs($admin)->getJson('/api/admin/external-integration-connections');
        $index->assertSuccessful();
        $index->assertJsonFragment(['client_id_masked' => '****1234']);
        $index->assertJsonMissing(['client_secret' => 'client-secret-5678']);

        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('aggregate_uuid', $id)
                ->where('event_class', 'external_integration_connection.created')
                ->exists(),
        );
    }

    public function test_provider_can_have_multiple_connections(): void
    {
        $admin = $this->admin();

        $payload = [
            'provider' => ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_API_KEY,
            'api_key' => 'api-key-1',
        ];
        $this->actingAs($admin)->postJson('/api/admin/external-integration-connections', $payload + ['name' => '第一事業所'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/admin/external-integration-connections', $payload + ['name' => '第二事業所'])->assertCreated();

        $this->assertSame(2, ExternalIntegrationConnection::query()->where('provider', ExternalIntegrationConnection::PROVIDER_MONEYFORWARD)->count());
    }

    public function test_oauth2_requires_client_id_and_secret(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/external-integration-connections', [
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'name' => 'freee',
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
        ])->assertUnprocessable();
    }

    public function test_admin_can_update_a_connection_and_blank_secret_keeps_existing_value(): void
    {
        $admin = $this->admin();
        $connection = ExternalIntegrationConnection::create([
            'id' => (string) Uuid::uuid4(),
            'provider' => ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            'name' => 'MF',
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_API_KEY,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'enabled' => false,
            'api_key' => 'original-key',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/external-integration-connections/{$connection->id}", [
            'name' => 'MF更新後',
            'enabled' => true,
            'api_key' => '',
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('enabled', true);
        $response->assertJsonPath('name', 'MF更新後');

        $connection->refresh();
        $this->assertSame('original-key', $connection->api_key);
        $this->assertTrue($connection->enabled);

        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('aggregate_uuid', $connection->id)
                ->where('event_class', 'external_integration_connection.updated')
                ->exists(),
        );
    }

    public function test_admin_can_delete_a_connection(): void
    {
        $admin = $this->admin();
        $connection = ExternalIntegrationConnection::create([
            'id' => (string) Uuid::uuid4(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'name' => 'freee',
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_API_KEY,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'api_key' => 'k',
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/external-integration-connections/{$connection->id}")->assertNoContent();

        $this->assertDatabaseMissing('external_integration_connections', ['id' => $connection->id]);
        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('aggregate_uuid', $connection->id)
                ->where('event_class', 'external_integration_connection.deleted')
                ->exists(),
        );
    }

    public function test_non_admin_cannot_manage_connections(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/admin/external-integration-connections')->assertForbidden();
        $this->actingAs($employee)->postJson('/api/admin/external-integration-connections', [
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'name' => 'x',
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_API_KEY,
            'api_key' => 'k',
        ])->assertForbidden();
    }
}
