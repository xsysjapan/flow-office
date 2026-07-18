<?php

namespace Tests\Feature\AuthenticationKey;

use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyStatus;
use App\Models\AuthenticationKeyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-K001〜UC-K003: 認証キー管理(docs/24-usecases-authentication-keys.md)。
 */
class AuthenticationKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_register_their_own_authentication_key(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => '本社ICカード',
            'raw_key_value' => '04AABBCCDD',
        ]);

        $response->assertCreated();
        $this->assertArrayNotHasKey('key_hash', $response->json());
        $this->assertArrayNotHasKey('raw_key_value', $response->json());

        $key = AuthenticationKey::query()->firstOrFail();
        $this->assertSame($employee->id, $key->user_id);
        $this->assertSame(AuthenticationKeyStatus::ACTIVE, $key->status);
        $this->assertNotSame('04AABBCCDD', $key->key_hash);
    }

    public function test_the_same_key_cannot_be_registered_to_two_active_users(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'カードA',
            'raw_key_value' => 'DUPLICATE-UID',
        ])->assertCreated();

        $this->actingAs($second)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'カードA(誤登録)',
            'raw_key_value' => 'DUPLICATE-UID',
        ])->assertStatus(422);
    }

    public function test_disabling_a_key_prevents_reuse_but_allows_reissue(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $created = $this->actingAs($first)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'カードB',
            'raw_key_value' => 'REISSUE-UID',
        ])->assertCreated();

        $this->actingAs($first)->postJson("/api/authentication-keys/{$created->json('id')}/disable")->assertSuccessful();

        // 無効化後は、同じ物理カード(同じ生値)を別の社員に再登録できる。
        $this->actingAs($second)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'カードB(再割当)',
            'raw_key_value' => 'REISSUE-UID',
        ])->assertCreated();
    }

    public function test_admin_can_register_a_key_on_behalf_of_an_employee(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/users/me/authentication-keys', [
            'user_id' => $employee->id,
            'key_type' => AuthenticationKeyType::FINGERPRINT_EXTERNAL_ID,
            'display_name' => '指紋端末ID',
            'raw_key_value' => 'FP-EXTERNAL-123',
        ]);

        $response->assertCreated();
        $this->assertSame($employee->id, AuthenticationKey::query()->firstOrFail()->user_id);
    }

    public function test_employee_cannot_register_a_key_on_behalf_of_another_employee(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/users/me/authentication-keys', [
            'user_id' => $other->id,
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => '不正登録',
            'raw_key_value' => 'SOME-UID',
        ])->assertForbidden();
    }
}
