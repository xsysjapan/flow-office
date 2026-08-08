<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\RecordSsoLogin;
use App\Models\ExternalIdentity;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\LocalDateTime;
use Illuminate\Support\Str;

/**
 * UC-001: Microsoft SSOでログインする。
 * Entra IDのユーザーID・メール・表示名を取得し、初回ログインならアプリ側ユーザーを作成、
 * 既存ユーザーなら最終ログイン日時を更新する。
 *
 * 初回オンボーディング(UC-000)でのSSO連携済み管理者作成は
 * `CompleteOnboardingSsoLinkHandler`が別途担当するため、ここでは`entra_user_id`未設定行への
 * リンクのような特別扱いは行わない(entra_user_idで見つからなければ常に新規=employeeロール
 * として作成する)。emailで既存行が見つかった場合は`UserSsoAccountLinked`イベントも
 * あわせて記録し、entra_user_idの紐付けをUserProjector経由で反映する。
 *
 * @implements CommandHandler<RecordSsoLogin>
 */
class RecordSsoLoginHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof RecordSsoLogin);

        $existingByEntraId = ExternalIdentity::query()->where('provider', 'MICROSOFT_ENTRA')->where('external_subject_id', $command->entraUserId)->where('status', 'active')->first()?->user
            ?? User::query()->where('entra_user_id', $command->entraUserId)->first();
        $wasFirstLogin = $existingByEntraId === null;

        // last_login_atのような一般的な日時はユーザー個別のタイムゾーンではなく、
        // システムのデフォルトタイムゾーンで記録する (docs/03-architecture.md 3.4)。
        $defaultTimezone = SystemSetting::current()->default_timezone;
        $loggedInAt = LocalDateTime::now($defaultTimezone)->format('Y-m-d H:i:s');

        if ($existingByEntraId !== null) {
            $this->assertAccountCanLogin($existingByEntraId);
            $userId = $existingByEntraId->id;
            UserAggregate::retrieve($userId)->recordLogin(wasFirstLogin: false, loggedInAt: $loggedInAt)->persist();

            return User::query()->findOrFail($userId);
        }

        $existingByEmail = User::query()->where('email', $command->email)->first();

        if ($existingByEmail !== null) {
            $this->assertAccountCanLogin($existingByEmail);
            $userId = $existingByEmail->id;
            UserAggregate::retrieve($userId)
                ->linkSsoAccount($command->entraUserId)
                ->recordLogin(wasFirstLogin: false, loggedInAt: $loggedInAt)
                ->persist();

            return User::query()->findOrFail($userId);
        }

        $userId = (string) Str::uuid();

        UserAggregate::retrieve($userId)
            ->createFromSsoLogin($command->entraUserId, $command->name ?? $command->email, $command->email)
            ->recordLogin(wasFirstLogin: $wasFirstLogin, loggedInAt: $loggedInAt)
            ->persist();

        return User::query()->findOrFail($userId);
    }

    private function assertAccountCanLogin(User $user): void
    {
        if (! in_array($user->account_status, ['active', 'leave'], true)) {
            throw new DomainRuleException('このアカウントは現在利用できません。');
        }
    }
}
