<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Commands\CompleteOnboardingSsoLink;
use App\Domain\User\Events\UserOnboardedAsAdmin;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * @implements CommandHandler<CompleteOnboardingSsoLink>
 */
class CompleteOnboardingSsoLinkHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): User
    {
        assert($command instanceof CompleteOnboardingSsoLink);

        $settings = SystemSetting::current();

        if ($settings->onboarding_started_at === null || $settings->onboarding_completed_at !== null) {
            throw new DomainRuleException('初回オンボーディング(Microsoft 365連携設定)が開始されていません。');
        }

        $conflict = User::query()
            ->where('entra_user_id', $command->entraUserId)
            ->when($command->email !== null, fn ($query) => $query->orWhere('email', $command->email))
            ->exists();

        if ($conflict) {
            throw new DomainRuleException('このEntra IDアカウント(またはメールアドレス)は既に登録済みのため、オンボーディングを完了できません。');
        }

        $adminRole = Role::query()->where('code', Role::ADMIN)->first();
        if ($adminRole === null) {
            throw new DomainRuleException('管理者ロールが未作成のため、オンボーディングを完了できません。ロールマスタの初期化(シード)を確認してください。');
        }

        $user = User::query()->create([
            'entra_user_id' => $command->entraUserId,
            'name' => $command->name,
            'email' => $command->email,
            'employment_status' => 'active',
            'timezone' => $settings->default_timezone,
        ]);

        $user->roles()->attach($adminRole);

        if (! SystemSetting::completeOnboarding()) {
            throw new DomainRuleException('初回オンボーディングは既に完了しています。');
        }

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserOnboardedAsAdmin(
                userId: $user->id,
                name: $user->name,
                email: $user->email,
                authMethod: 'sso',
            ),
        );

        return $user;
    }
}
