<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * UC-001: Microsoft SSOでログインする(ワンタイムコード交換部分)。
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_code_can_be_redeemed_once_for_a_token(): void
    {
        $user = User::factory()->create();
        Cache::put('sso-exchange:test-code', $user->id, now()->addMinute());

        $response = $this->postJson('/api/auth/token', ['code' => 'test-code']);
        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $token = $response->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        // ワンタイムなので再利用はできない
        $this->postJson('/api/auth/token', ['code' => 'test-code'])->assertStatus(422);
    }

    public function test_invalid_code_is_rejected(): void
    {
        $this->postJson('/api/auth/token', ['code' => 'does-not-exist'])->assertStatus(422);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_api_protected_routes_return_401_without_json_accept_header(): void
    {
        $this->get('/api/auth/me')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
