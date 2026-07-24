<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\User\Aggregates\UserAggregate;
use App\Domain\User\Commands\RecordLocalLogin;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\LocalDateTime;

/**
 * @implements CommandHandler<RecordLocalLogin>
 */
class RecordLocalLoginHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof RecordLocalLogin);

        $user = User::query()->findOrFail($command->userId);
        $defaultTimezone = SystemSetting::current()->default_timezone;
        $loggedInAt = LocalDateTime::now($defaultTimezone)->format('Y-m-d H:i:s');

        // ローカルパスワードログインはオンボーディングで作成済みのアカウントに対する
        // ログインのみで、SSOのような「初回ログインで新規作成」は起こらないため常にfalse。
        UserAggregate::retrieve($user->id)->recordLogin(wasFirstLogin: false, loggedInAt: $loggedInAt)->persist();

        return $user->refresh();
    }
}
