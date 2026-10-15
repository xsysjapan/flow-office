<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Domain\SpecialLeaveAccount\Commands\RegisterSpecialLeaveGrant;
use App\Jobs\SendNotificationJob;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 特別休暇を付与する。付与の記録は利用者単位の特別休暇口座(SpecialLeaveAccount)の集約が行う
 * (`RegisterSpecialLeaveGrant`。以後の付与・消化の残数は口座集約が知る。仕様確定事項D)。
 * 付与の行は口座のイベントから作られる(SpecialLeaveAccountProjector)。
 *
 * @implements CommandHandler<GrantSpecialLeave>
 */
class GrantSpecialLeaveHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function handle(Command $command): SpecialLeaveGrant
    {
        assert($command instanceof GrantSpecialLeave);

        $grantId = (string) Str::uuid();

        $this->commandBus->dispatch(new RegisterSpecialLeaveGrant(
            userId: $command->userId,
            grantId: $grantId,
            specialLeaveTypeId: $command->specialLeaveTypeId,
            grantedOn: $command->grantedOn,
            expiresOn: $command->expiresOn,
            grantedDays: $command->grantedDays,
            grantReason: $command->grantReason,
        ));

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
