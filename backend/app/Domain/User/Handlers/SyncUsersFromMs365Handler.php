<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\User\Commands\SyncUsersFromMs365;
use App\Domain\User\Events\UserSyncedFromMs365;
use App\Domain\User\Graph\MicrosoftGraphClient;
use App\Domain\User\Graph\MicrosoftGraphUser;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * UC-002: MS365ユーザーを同期する。
 * 氏名・メール・部署・役職・在籍状態のみを更新し、アプリ独自のロールは一切変更しない。
 *
 * @implements CommandHandler<SyncUsersFromMs365>
 */
class SyncUsersFromMs365Handler implements CommandHandler
{
    public function __construct(
        private readonly MicrosoftGraphClient $graphClient,
        private readonly EventStore $eventStore,
    ) {}

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
        $wasCreated = $user === null;

        $attributes = [
            'entra_user_id' => $graphUser->entraUserId,
            'name' => $graphUser->displayName,
            'email' => $graphUser->mail ?? $user?->email,
            'department' => $graphUser->department,
            'job_title' => $graphUser->jobTitle,
            'employment_status' => $graphUser->employmentStatus(),
        ];

        if ($user === null) {
            $user = User::query()->create($attributes);
        } else {
            $user->fill($attributes);
            $user->save();
        }

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserSyncedFromMs365(
                userId: $user->id,
                wasCreated: $wasCreated,
                changes: $attributes,
            ),
        );
    }
}
