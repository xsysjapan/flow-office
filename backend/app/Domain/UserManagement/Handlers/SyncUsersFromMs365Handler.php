<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\SyncUsersFromMs365;
use App\Domain\UserManagement\Graph\MicrosoftGraphClient;
use App\Domain\UserManagement\Graph\MicrosoftGraphUser;
use App\Domain\UserManagement\Services\FieldAuthorityService;
use App\Domain\UserManagement\Services\StandardGroupMembershipRecorder;
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
    private readonly FieldAuthorityService $fieldAuthorities;

    public function __construct(
        private readonly MicrosoftGraphClient $graphClient,
        private readonly StandardGroupMembershipRecorder $standardMemberships,
        ?FieldAuthorityService $fieldAuthorities = null,
    ) {
        $this->fieldAuthorities = $fieldAuthorities ?? app(FieldAuthorityService::class);
    }

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
                name: $this->fieldAuthorities->isExternalHr('display_name') && $user ? $user->name : $graphUser->displayName,
                email: $this->fieldAuthorities->isExternalHr('email') && $user ? $user->email : ($graphUser->mail ?? $user?->email),
                department: $this->fieldAuthorities->isExternalHr('department') && $user ? $user->department : $graphUser->department,
                jobTitle: $this->fieldAuthorities->isExternalHr('job_title') && $user ? $user->job_title : $graphUser->jobTitle,
                employmentStatus: $this->fieldAuthorities->isExternalHr('employment_status') && $user ? $user->employment_status : $graphUser->employmentStatus(),
            )
            ->persist();

        if ($user === null) {
            $this->standardMemberships->add($userId, ['ALL_USERS'], $userId);
        }
    }
}
