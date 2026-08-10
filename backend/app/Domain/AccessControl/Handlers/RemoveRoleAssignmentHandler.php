<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAssignmentAggregate;
use App\Domain\AccessControl\Commands\RemoveRoleAssignment;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<RemoveRoleAssignment> */
class RemoveRoleAssignmentHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof RemoveRoleAssignment);
        if (! DB::table('role_assignments')->where('id', $command->assignmentId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なRole割当が存在しません。');
        } RoleAssignmentAggregate::retrieve($command->assignmentId)->remove($command->actorUserId)->persist();

        return $command->assignmentId;
    }
}
