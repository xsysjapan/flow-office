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
            // 初回オンボーディング(CompleteOnboardingHandler)で作成された管理者は、この時点では
            // entra_user_idが未設定のまま登録されている。メールが一致する未リンクの行があれば
            // 新規作成せずそこにentra_user_idをバックフィルし、付与済みの管理者ロールを維持する。
            $unlinkedUser = $command->email !== null
                ? User::query()->whereNull('entra_user_id')->where('email', $command->email)->first()
                : null;

            if ($unlinkedUser !== null) {
                $unlinkedUser->entra_user_id = $command->entraUserId;
                if ($command->name !== null) {
                    $unlinkedUser->name = $command->name;
                }
                $user = $unlinkedUser;
                $wasFirstLogin = false;
            } else {
                $user = User::query()->create([
                    'entra_user_id' => $command->entraUserId,
                    'name' => $command->name ?? $command->email,
                    'email' => $command->email,
                    'employment_status' => 'active',
                    'timezone' => $defaultTimezone,
                ]);

                $user->roles()->attach(Role::query()->where('code', Role::EMPLOYEE)->first());
            }
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
