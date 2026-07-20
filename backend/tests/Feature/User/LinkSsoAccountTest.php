<?php

namespace Tests\Feature\User;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Commands\LinkSsoAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-004: ローカルパスワードユーザーが任意のタイミングでMicrosoft 365アカウントと連携する。
 */
class LinkSsoAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_user_can_link_an_sso_account(): void
    {
        $user = User::factory()->create(['password' => 'secret1234', 'entra_user_id' => null]);

        $linked = app(CommandBus::class)->dispatch(new LinkSsoAccount(
            userId: $user->id,
            entraUserId: 'entra-link-1',
        ));

        $this->assertSame('entra-link-1', $linked->entra_user_id);
        // ローカルパスワードは維持される(連携後も両方でログインできる)。
        $this->assertNotNull($linked->refresh()->password);
    }

    public function test_relinking_the_same_account_is_idempotent(): void
    {
        $user = User::factory()->create(['entra_user_id' => 'entra-link-2']);

        $linked = app(CommandBus::class)->dispatch(new LinkSsoAccount(
            userId: $user->id,
            entraUserId: 'entra-link-2',
        ));

        $this->assertSame('entra-link-2', $linked->entra_user_id);
    }

    public function test_cannot_link_when_already_linked_to_a_different_account(): void
    {
        $user = User::factory()->create(['entra_user_id' => 'entra-existing']);

        $this->expectException(DomainRuleException::class);

        app(CommandBus::class)->dispatch(new LinkSsoAccount(
            userId: $user->id,
            entraUserId: 'entra-other',
        ));
    }

    public function test_cannot_link_an_sso_account_already_used_by_another_user(): void
    {
        User::factory()->create(['entra_user_id' => 'entra-taken']);
        $user = User::factory()->create(['entra_user_id' => null]);

        $this->expectException(DomainRuleException::class);

        app(CommandBus::class)->dispatch(new LinkSsoAccount(
            userId: $user->id,
            entraUserId: 'entra-taken',
        ));
    }

    public function test_link_redirect_requires_authentication(): void
    {
        $this->getJson('/api/auth/microsoft/link-redirect')->assertStatus(401);
    }

    public function test_link_redirect_returns_a_microsoft_login_url_for_the_current_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/microsoft/link-redirect')
            ->assertOk()
            ->assertJsonStructure(['url']);
    }
}
