<?php

namespace App\Domain\AccessControl\Reactors;

use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Events\GroupCreated;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * グループの作成に初期ロールがあれば、そのグループへ全社(global)・配下を含まない・期間なしのRole割当を作る
 * (AccessControl文脈のReactor。原則15)。割当のIDはグループのIDから決まるuuid5で、viaReactorのCommandは
 * 作成済みなら何もしない(冪等)。割当が拒否されたら、グループの作成ごと戻し、拒否の理由をinitial_role_idの項目のエラーにする。
 */
class CreateInitialRoleAssignmentOnGroupCreatedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onGroupCreated(GroupCreated $event): void
    {
        if ($event->initialRoleId === null) {
            return;
        }

        $groupId = $event->aggregateRootUuid();

        try {
            $this->commandBus->dispatch(new CreateRoleAssignment(
                assignmentId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-initial-role:'.$groupId)->toString(),
                subjectType: 'group',
                subjectId: $groupId,
                roleId: $event->initialRoleId,
                scopeType: 'global',
                scopeGroupId: null,
                includesDescendants: false,
                startsAt: null,
                endsAt: null,
                actorUserId: $event->actorUserId,
                viaReactor: true,
                initiatedByUserId: $event->actorUserId,
            ));
        } catch (DomainRuleException $e) {
            throw ValidationException::withMessages(['initial_role_id' => [$e->getMessage()]]);
        }
    }
}
