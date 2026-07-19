<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\User\Commands\RecordSsoLogin;
use App\Domain\User\Events\UserLoggedIn;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\LocalDateTime;

/**
 * UC-001: Microsoft SSOでログインする。
 * Entra IDのユーザーID・メール・表示名を取得し、初回ログインならアプリ側ユーザーを作成、
 * 既存ユーザーなら最終ログイン日時を更新する。
 *
 * 初回オンボーディング(UC-000)でのSSO連携済み管理者作成は
 * `CompleteOnboardingSsoLinkHandler`が別途担当するため、ここでは`entra_user_id`未設定行への
 * リンクのような特別扱いは行わない(entra_user_idで見つからなければ常に新規=employeeロール
 * として作成する)。
 *
 * @implements CommandHandler<RecordSsoLogin>
 */
class RecordSsoLoginHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): User
    {
        assert($command instanceof RecordSsoLogin);

        $user = User::query()->where('entra_user_id', $command->entraUserId)->first();
        $wasFirstLogin = $user === null;

        // last_login_atのような一般的な日時はユーザー個別のタイムゾーンではなく、
        // システムのデフォルトタイムゾーンで記録する (docs/03-architecture.md 3.4)。
        $defaultTimezone = SystemSetting::current()->default_timezone;

        if ($user === null) {
            $user = User::query()->create([
                'entra_user_id' => $command->entraUserId,
                'name' => $command->name ?? $command->email,
                'email' => $command->email,
                'employment_status' => 'active',
                'timezone' => $defaultTimezone,
            ]);

            $user->roles()->attach(Role::query()->where('code', Role::EMPLOYEE)->first());
        }

        $user->last_login_at = LocalDateTime::now($defaultTimezone);
        $user->save();

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserLoggedIn(userId: $user->id, wasFirstLogin: $wasFirstLogin),
        );

        return $user;
    }
}
