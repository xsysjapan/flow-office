<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Aggregates\UserAggregate;
use App\Domain\User\Commands\CompleteOnboardingWithLocalPassword;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @implements CommandHandler<CompleteOnboardingWithLocalPassword>
 */
class CompleteOnboardingWithLocalPasswordHandler implements CommandHandler
{
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

        $userId = (string) Str::uuid();

        UserAggregate::retrieve($userId)
            ->onboardAsAdmin(entraUserId: null, name: $command->adminName, email: $command->adminEmail, authMethod: 'local')
            ->persist();

        // パスワードは平文を永続イベントログに残さないため、イベントには含めず
        // Projectorが作成した行に対して直接設定する(docs/29-event-sourcing-framework-migration.md参照)。
        $user = User::query()->findOrFail($userId);
        $user->password = $command->adminPassword;
        $user->save();

        return $user;
    }
}
