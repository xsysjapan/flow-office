<?php

namespace App\Domain\AccessControl\Reactors;

use App\Domain\AccessControl\Commands\SyncGroupFeatures;
use App\Domain\AccessControl\Events\RoleAssignmentCreated;
use App\Domain\AccessControl\Events\RoleAssignmentRemoved;
use App\Domain\AccessControl\Events\RoleAssignmentUpdated;
use App\Domain\AccessControl\Events\RoleFeaturesChanged;
use App\Domain\AccessControl\Services\GroupFeatureSyncService;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Role割当の作成・更新・削除とRoleのFeature変更を受け、対象のグループごとにSyncGroupFeaturesを発行する
 * (AccessControl文脈の中の別の集約への連鎖。原則15)。同期は差分が無ければ何も記録しないので、同じイベントを
 * 2回処理しても結果は変わらない。割当の更新・削除のイベントには付与先が無いので、割当の行(role_assignments)から引く。
 * 無効なロールの扱い・期間の到来・グループの再有効化では同期しない(今までと同じ)。
 */
class SyncGroupFeaturesOnRoleChangeReactor extends Reactor
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly GroupFeatureSyncService $syncService,
    ) {}

    public function onRoleAssignmentCreated(RoleAssignmentCreated $event): void
    {
        if ($event->subjectType === 'group') {
            $this->sync($event->subjectId, $event->actorUserId);
        }
    }

    public function onRoleAssignmentUpdated(RoleAssignmentUpdated $event): void
    {
        $this->syncGroupOfAssignment($event->aggregateRootUuid(), $event->actorUserId);
    }

    public function onRoleAssignmentRemoved(RoleAssignmentRemoved $event): void
    {
        $this->syncGroupOfAssignment($event->aggregateRootUuid(), $event->actorUserId);
    }

    public function onRoleFeaturesChanged(RoleFeaturesChanged $event): void
    {
        foreach ($this->syncService->groupIdsForRole($event->roleId) as $groupId) {
            $this->sync($groupId, $event->actorUserId);
        }
    }

    private function syncGroupOfAssignment(string $assignmentId, ?string $actorUserId): void
    {
        $assignment = DB::table('role_assignments')->where('id', $assignmentId)->first(['subject_type', 'subject_id']);
        if ($assignment !== null && $assignment->subject_type === 'group') {
            $this->sync((string) $assignment->subject_id, $actorUserId);
        }
    }

    private function sync(string $groupId, ?string $actorUserId): void
    {
        $this->commandBus->dispatch(new SyncGroupFeatures($groupId, $actorUserId));
    }
}
