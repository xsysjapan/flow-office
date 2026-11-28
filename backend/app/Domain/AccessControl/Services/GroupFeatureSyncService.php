<?php

namespace App\Domain\AccessControl\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Role→Feature自動適用の差分の計算: グループへの有効なRole割当から導かれるFeatureの集合と、
 * `group_feature_assignments` の今の集合の差を求める。記録はSyncGroupFeaturesHandler(グループのFeature集約)が行い、
 * 発行はAccessControlのReactorが行う。期間外・削除済みの割当は導かれる集合に含めない(ロールの状態の変更・期間の到来・グループの再有効化では再計算しない)。
 */
class GroupFeatureSyncService
{
    /**
     * @return array{add: list<int>, remove: list<int>}
     */
    public function diffForGroup(string $groupId): array
    {
        if (! DB::table('groups')->where('id', $groupId)->where('status', 'active')->exists()) {
            return ['add' => [], 'remove' => []];
        }

        $targetFeatureIds = $this->effectiveFeatureIdsForGroup($groupId);
        $currentFeatureIds = DB::table('group_feature_assignments')
            ->where('group_id', $groupId)
            ->pluck('feature_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'add' => array_values(array_diff($targetFeatureIds, $currentFeatureIds)),
            'remove' => array_values(array_diff($currentFeatureIds, $targetFeatureIds)),
        ];
    }

    /**
     * ロールを今有効に持つグループのID。
     *
     * @return list<string>
     */
    public function groupIdsForRole(int $roleId): array
    {
        return $this->activeRoleAssignments()
            ->where('role_assignments.role_id', $roleId)
            ->where('role_assignments.subject_type', 'group')
            ->distinct()
            ->pluck('role_assignments.subject_id')
            ->map(fn ($id) => (string) $id)
            ->all();
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

    private function activeRoleAssignments(): Builder
    {
        $now = Carbon::now();

        return DB::table('role_assignments')
            ->where('role_assignments.status', 'active')
            ->where(fn ($q) => $q->whereNull('role_assignments.starts_at')->orWhere('role_assignments.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('role_assignments.ends_at')->orWhere('role_assignments.ends_at', '>=', $now));
    }
}
