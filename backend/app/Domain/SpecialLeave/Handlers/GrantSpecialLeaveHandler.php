<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Domain\SpecialLeave\Events\SpecialLeaveGranted;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;

/**
 * @implements CommandHandler<GrantSpecialLeave>
 */
class GrantSpecialLeaveHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): SpecialLeaveGrant
    {
        assert($command instanceof GrantSpecialLeave);

        $grant = SpecialLeaveGrant::query()->create([
            'user_id' => $command->userId,
            'special_leave_type_id' => $command->specialLeaveTypeId,
            'granted_on' => $command->grantedOn,
            'expires_on' => $command->expiresOn,
            'granted_days' => $command->grantedDays,
            'used_days' => 0,
            'remaining_days' => $command->grantedDays,
            'grant_reason' => $command->grantReason,
        ]);

        $this->eventStore->append(
            aggregateType: 'special_leave_grant',
            aggregateId: (string) $grant->id,
            event: new SpecialLeaveGranted(
                specialLeaveGrantId: $grant->id,
                userId: $command->userId,
                specialLeaveTypeId: $command->specialLeaveTypeId,
                grantedOn: $command->grantedOn,
                expiresOn: $command->expiresOn,
                grantedDays: $command->grantedDays,
            ),
        );

        $typeName = SpecialLeaveType::query()->find($command->specialLeaveTypeId)?->name ?? '特別休暇';
        $expiryText = $command->expiresOn !== null ? "(有効期限: {$command->expiresOn})" : '(失効しない付与)';

        SendTeamsNotificationJob::enqueue(
            title: '特別休暇付与',
            summary: "{$typeName}が{$command->grantedDays}日付与されました{$expiryText}。",
            detailUrl: null,
        );

        return $grant;
    }
}
