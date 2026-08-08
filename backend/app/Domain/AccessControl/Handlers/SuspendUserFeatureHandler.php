<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\UserFeatureSuspensionAggregate;
use App\Domain\AccessControl\Commands\SuspendUserFeature;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<SuspendUserFeature> */
class SuspendUserFeatureHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof SuspendUserFeature);
        if ($command->startsAt && $command->endsAt && $command->startsAt > $command->endsAt) {
            throw new DomainRuleException('開始日時は終了日時以前にしてください。');
        } if (! DB::table('users')->where('id', $command->userId)->exists() || ! DB::table('features')->where('id', $command->featureId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なユーザーとFeatureを指定してください。');
        } $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'user-feature-suspension:'.$command->userId)->toString();
        UserFeatureSuspensionAggregate::retrieve($id)->suspend((string) Uuid::uuid4(), $command->userId, $command->featureId, $command->reason, $command->startsAt, $command->endsAt, $command->actorUserId)->persist();

        return $command->userId;
    }
}
