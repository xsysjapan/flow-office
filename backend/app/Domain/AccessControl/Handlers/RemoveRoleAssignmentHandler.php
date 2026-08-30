<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAssignmentAggregate;
use App\Domain\AccessControl\Commands\RemoveRoleAssignment;
use App\Domain\AccessControl\Services\GroupFeatureSyncService;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<RemoveRoleAssignment> */
class RemoveRoleAssignmentHandler implements CommandHandler
{
    public function __construct(private readonly GroupFeatureSyncService $groupFeatureSync) {}

    public function handle(Command $command): string
    {
        assert($command instanceof RemoveRoleAssignment);
        $assignment = DB::table('role_assignments')->where('id', $command->assignmentId)->where('status', 'active')->first();
        if (! $assignment) {
            throw new DomainRuleException('有効なRole割当が存在しません。');
        } RoleAssignmentAggregate::retrieve($command->assignmentId)->remove($command->actorUserId)->persist();

        if ($assignment->subject_type === 'group') {
            $this->groupFeatureSync->syncGroup($assignment->subject_id, $command->actorUserId);
        }

        return $command->assignmentId;
    }
}
