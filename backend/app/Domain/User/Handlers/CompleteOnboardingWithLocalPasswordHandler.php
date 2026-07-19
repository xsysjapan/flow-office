<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Commands\CompleteOnboardingWithLocalPassword;
use App\Domain\User\Events\UserOnboardedAsAdmin;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * @implements CommandHandler<CompleteOnboardingWithLocalPassword>
 */
class CompleteOnboardingWithLocalPasswordHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): User
    {
        assert($command instanceof CompleteOnboardingWithLocalPassword);

        $settings = SystemSetting::current();

        if (User::query()->where('email', $command->adminEmail)->exists()) {
            throw new DomainRuleException('このメールアドレスは既に登録済みのため、オンボーディングを完了できません。');
        }

        $adminRole = Role::query()->where('code', Role::ADMIN)->first();
        if ($adminRole === null) {
            throw new DomainRuleException('管理者ロールが未作成のため、オンボーディングを完了できません。ロールマスタの初期化(シード)を確認してください。');
        }

        // ローカルモードは1リクエストで完結するため、開始(onboarding_started_at)と
        // 完了(onboarding_completed_at)を同時に原子的コミットする。
        if (! SystemSetting::completeOnboarding(['onboarding_started_at' => now()])) {
            throw new DomainRuleException('初回オンボーディングは既に開始または完了しています。');
        }

        $user = User::query()->create([
            'name' => $command->adminName,
            'email' => $command->adminEmail,
            'password' => $command->adminPassword,
            'employment_status' => 'active',
            'timezone' => $settings->default_timezone,
        ]);

        $user->roles()->attach($adminRole);

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserOnboardedAsAdmin(
                userId: $user->id,
                name: $user->name,
                email: $user->email,
                authMethod: 'local',
            ),
        );

        return $user;
    }
}
