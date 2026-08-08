<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\GroupAggregate;
use App\Domain\UserManagement\Commands\CreateGroup;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<CreateGroup> */
class CreateGroupHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof CreateGroup);
        if (DB::table('groups')->where('code', $command->code)->exists()) {
            throw new DomainRuleException('グループコードは既に使用されています。');
        } if (! DB::table('group_types')->where('id', $command->groupTypeId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なGroupTypeを指定してください。');
        } if ($command->parentGroupId && ! DB::table('groups')->where('id', $command->parentGroupId)->where('group_type_id', $command->groupTypeId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('同じGroupTypeの有効な親グループを指定してください。');
        } GroupAggregate::retrieve($command->groupId)->create($command->groupTypeId, $command->name, $command->code, $command->description, $command->parentGroupId, $command->actorUserId)->persist();

        return $command->groupId;
    }
}
