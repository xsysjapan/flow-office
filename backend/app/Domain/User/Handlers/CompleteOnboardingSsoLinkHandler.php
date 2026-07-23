<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Aggregates\UserAggregate;
use App\Domain\User\Commands\CompleteOnboardingSsoLink;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @implements CommandHandler<CompleteOnboardingSsoLink>
 */
class CompleteOnboardingSsoLinkHandler implements CommandHandler
{
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

        // 完了フラグの原子的なコミットを先に確定させ、失敗する場合はユーザー行を
        // 作らない(先にユーザーを作ってしまうと、完了フラグの競合時に孤立行が残るため)。
        if (! SystemSetting::completeOnboarding()) {
            throw new DomainRuleException('初回オンボーディングは既に完了しています。');
        }

        $userId = (string) Str::uuid();

        UserAggregate::retrieve($userId)
            ->onboardAsAdmin($command->entraUserId, $command->name, $command->email, 'sso')
            ->persist();

        return User::query()->findOrFail($userId);
    }
}
