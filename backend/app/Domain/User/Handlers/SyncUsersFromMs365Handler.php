<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\User\Aggregates\UserAggregate;
use App\Domain\User\Commands\SyncUsersFromMs365;
use App\Domain\User\Graph\MicrosoftGraphClient;
use App\Domain\User\Graph\MicrosoftGraphUser;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * UC-002: MS365ユーザーを同期する。
 * 氏名・メール・部署・役職・在籍状態のみを更新し、アプリ独自のロールは一切変更しない。
 *
 * @implements CommandHandler<SyncUsersFromMs365>
 */
class SyncUsersFromMs365Handler implements CommandHandler
{
    public function __construct(private readonly MicrosoftGraphClient $graphClient) {}

    public function handle(Command $command): int
    {
        assert($command instanceof SyncUsersFromMs365);

        $syncedCount = 0;

        foreach ($this->graphClient->listUsers() as $graphUser) {
            $this->syncOne($graphUser);
            $syncedCount++;
        }

        Log::info("MS365ユーザー同期完了: {$syncedCount}件");

        return $syncedCount;
    }

    private function syncOne(MicrosoftGraphUser $graphUser): void
    {
        $user = User::query()->where('entra_user_id', $graphUser->entraUserId)->first();
        $userId = $user->id ?? (string) Str::uuid();

        UserAggregate::retrieve($userId)
            ->syncFromMs365(
                entraUserId: $graphUser->entraUserId,
                name: $graphUser->displayName,
                email: $graphUser->mail ?? $user?->email,
                department: $graphUser->department,
                jobTitle: $graphUser->jobTitle,
                employmentStatus: $graphUser->employmentStatus(),
            )
            ->persist();
    }
}
