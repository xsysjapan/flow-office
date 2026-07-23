<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveGrantAggregate;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Jobs\SendNotificationJob;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @implements CommandHandler<GrantSpecialLeave>
 */
class GrantSpecialLeaveHandler implements CommandHandler
{
    public function handle(Command $command): SpecialLeaveGrant
    {
        assert($command instanceof GrantSpecialLeave);

        $grantId = (string) Str::uuid();

        SpecialLeaveGrantAggregate::retrieve($grantId)
            ->grant(
                userId: $command->userId,
                specialLeaveTypeId: $command->specialLeaveTypeId,
                grantedOn: $command->grantedOn,
                expiresOn: $command->expiresOn,
                grantedDays: $command->grantedDays,
                grantReason: $command->grantReason,
            )
            ->persist();

        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);

        $typeName = SpecialLeaveType::query()->find($command->specialLeaveTypeId)?->name ?? '特別休暇';
        $expiryText = $command->expiresOn !== null ? "(有効期限: {$command->expiresOn})" : '(失効しない付与)';

        $user = User::find($command->userId);
        if ($user !== null) {
            SendNotificationJob::enqueue(
                recipient: $user,
                title: '特別休暇付与',
                summary: "{$typeName}が{$command->grantedDays}日付与されました{$expiryText}。",
                detailUrl: null,
            );
        }

        return $grant;
    }
}
