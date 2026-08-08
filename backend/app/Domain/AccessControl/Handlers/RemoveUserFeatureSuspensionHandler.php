<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\UserFeatureSuspensionAggregate;
use App\Domain\AccessControl\Commands\RemoveUserFeatureSuspension;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<RemoveUserFeatureSuspension> */
class RemoveUserFeatureSuspensionHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof RemoveUserFeatureSuspension);
        $row = DB::table('user_feature_suspensions')->where('id', $command->suspensionId)->first();
        if (! $row) {
            throw new DomainRuleException('個別停止が存在しません。');
        } $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'user-feature-suspension:'.$row->user_id)->toString();
        UserFeatureSuspensionAggregate::retrieve($id)->remove($command->suspensionId, $row->user_id, $command->actorUserId)->persist();

        return $row->user_id;
    }
}
