<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Commands\CompleteOnboarding;
use App\Domain\User\Events\UserOnboardedAsAdmin;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * @implements CommandHandler<CompleteOnboarding>
 */
class CompleteOnboardingHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): User
    {
        assert($command instanceof CompleteOnboarding);

        $settings = SystemSetting::current();

        if ($settings->onboarding_completed_at !== null) {
            throw new DomainRuleException('初回オンボーディングは既に完了しています。');
        }

        $settings->update([
            'm365_tenant_id' => $command->m365TenantId,
            'm365_client_id' => $command->m365ClientId,
            'm365_client_secret' => $command->m365ClientSecret,
            'm365_redirect_uri' => $command->m365RedirectUri,
            'm365_mock_enabled' => $command->m365MockEnabled,
            'onboarding_completed_at' => now(),
        ]);

        // entra_user_idはこの時点では未設定のまま作成する。実際のSSO初回ログイン時に
        // RecordSsoLoginHandlerがメール一致でこの行を見つけてバックフィルする。
        $user = User::query()->firstOrCreate(
            ['email' => $command->adminEmail],
            [
                'name' => $command->adminName,
                'employment_status' => 'active',
                'timezone' => $settings->default_timezone,
            ],
        );

        $adminRole = Role::query()->where('code', Role::ADMIN)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserOnboardedAsAdmin(
                userId: $user->id,
                name: $user->name,
                email: $user->email,
            ),
        );

        return $user;
    }
}
