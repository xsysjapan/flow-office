<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Commands\StartOnboardingSso;
use App\Models\SystemSetting;

/**
 * @implements CommandHandler<StartOnboardingSso>
 */
class StartOnboardingSsoHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof StartOnboardingSso);

        $claimed = SystemSetting::claimOnboarding([
            'm365_tenant_id' => $command->m365TenantId,
            'm365_client_id' => $command->m365ClientId,
            'm365_client_secret' => $command->m365ClientSecret,
            'm365_redirect_uri' => $command->m365RedirectUri,
            'm365_mock_enabled' => $command->m365MockEnabled,
            'onboarding_started_at' => now(),
        ]);

        if (! $claimed) {
            throw new DomainRuleException('初回オンボーディングは既に開始または完了しています。');
        }

        return null;
    }
}
