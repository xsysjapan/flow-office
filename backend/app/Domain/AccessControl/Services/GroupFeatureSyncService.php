<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Commands\AssignFeatureToGroup;
use App\Domain\AccessControl\Commands\RemoveFeatureFromGroup;
use App\Domain\AccessControl\Handlers\AssignFeatureToGroupHandler;
use App\Domain\AccessControl\Handlers\RemoveFeatureFromGroupHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Role→Feature自動適用: グループへの有効なRole割当から導かれるFeatureの集合を
 * `group_feature_assignments` に差分同期する。全消し全入れではなく、追加分だけ
 * AssignFeatureToGroup、不要分だけRemoveFeatureFromGroupを既存のCommandHandler経由で
 * 呼び出す(docs/03-architecture.md「EventStoreを正とする」原則に従い、直接
 * group_feature_assignmentsを書き換えない)。
 */
class GroupFeatureSyncService
{
    public function __construct(
        private readonly AssignFeatureToGroupHandler $assignFeatureToGroupHandler,
        private readonly RemoveFeatureFromGroupHandler $removeFeatureFromGroupHandler,
    ) {}

    public function syncGroup(string $groupId, ?string $actorUserId = null): void
    {
        if (! DB::table('groups')->where('id', $groupId)->where('status', 'active')->exists()) {
            return;
        }

        $targetFeatureIds = $this->effectiveFeatureIdsForGroup($groupId);
        $currentFeatureIds = DB::table('group_feature_assignments')
            ->where('group_id', $groupId)
            ->pluck('feature_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $toAdd = array_diff($targetFeatureIds, $currentFeatureIds);
        $toRemove = array_diff($currentFeatureIds, $targetFeatureIds);

        foreach ($toAdd as $featureId) {
            $this->assignFeatureToGroupHandler->handle(new AssignFeatureToGroup($groupId, $featureId, $actorUserId));
        }

        foreach ($toRemove as $featureId) {
            $this->removeFeatureFromGroupHandler->handle(new RemoveFeatureFromGroup($groupId, $featureId, $actorUserId));
        }
    }

    public function syncGroupsForRole(int $roleId, ?string $actorUserId = null): void
    {
        $groupIds = $this->activeRoleAssignments()
            ->where('role_assignments.role_id', $roleId)
            ->where('role_assignments.subject_type', 'group')
            ->distinct()
            ->pluck('role_assignments.subject_id');

        foreach ($groupIds as $groupId) {
            $this->syncGroup((string) $groupId, $actorUserId);
        }
    }

    /**
     * @return array<int, int>
     */
    private function effectiveFeatureIdsForGroup(string $groupId): array
    {
        return $this->activeRoleAssignments()
            ->where('role_assignments.subject_type', 'group')
            ->where('role_assignments.subject_id', $groupId)
            ->join('role_features', 'role_features.role_id', '=', 'role_assignments.role_id')
            ->join('features', 'features.id', '=', 'role_features.feature_id')
            ->where('features.status', 'active')
            ->distinct()
            ->pluck('role_features.feature_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function activeRoleAssignments(): \Illuminate\Database\Query\Builder
    {
        $now = Carbon::now();

        return DB::table('role_assignments')
            ->where('role_assignments.status', 'active')
            ->where(fn ($q) => $q->whereNull('role_assignments.starts_at')->orWhere('role_assignments.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('role_assignments.ends_at')->orWhere('role_assignments.ends_at', '>=', $now));
    }
}
