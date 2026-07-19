<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\User\Commands\RecordLocalLogin;
use App\Domain\User\Events\UserLoggedIn;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\LocalDateTime;

/**
 * @implements CommandHandler<RecordLocalLogin>
 */
class RecordLocalLoginHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): User
    {
        assert($command instanceof RecordLocalLogin);

        $user = User::query()->findOrFail($command->userId);
        $defaultTimezone = SystemSetting::current()->default_timezone;

        $user->last_login_at = LocalDateTime::now($defaultTimezone);
        $user->save();

        // ローカルパスワードログインはオンボーディングで作成済みのアカウントに対する
        // ログインのみで、SSOのような「初回ログインで新規作成」は起こらないため常にfalse。
        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserLoggedIn(userId: $user->id, wasFirstLogin: false),
        );

        return $user;
    }
}
