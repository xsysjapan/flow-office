<?php

namespace App\Domain\AccessControl\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EffectiveAccessResolver
{
    /** @return array{features: array<int, array<string, mixed>>, roles: array<int, array<string, mixed>>, permissions: array<int, array<string, mixed>>} */
    public function explain(User $user): array
    {
        $now = now();
        $groupIds = DB::table('memberships')->where('user_id', $user->id)->pluck('group_id');
        $suspendedFeatureIds = DB::table('user_feature_suspensions')
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->pluck('feature_id');
        $featureRows = DB::table('group_feature_assignments')
            ->join('features', 'features.id', '=', 'group_feature_assignments.feature_id')
            ->join('groups', 'groups.id', '=', 'group_feature_assignments.group_id')
            ->whereIn('group_feature_assignments.group_id', $groupIds)
            ->where('features.status', 'active')
            ->where('groups.status', 'active')
            ->whereNotIn('features.id', $suspendedFeatureIds)
            ->where(fn ($query) => $query->whereNull('features.parent_feature_id')->orWhereNotIn('features.parent_feature_id', $suspendedFeatureIds))
            ->select('features.id', 'features.code', 'features.name', 'groups.id as group_id', 'groups.name as group_name')
            ->get();
        $features = $featureRows->groupBy('id')->map(fn (Collection $rows) => [
            'code' => $rows->first()->code,
            'name' => $rows->first()->name,
            'sources' => $rows->map(fn ($row) => ['type' => 'group', 'group_id' => $row->group_id, 'group_name' => $row->group_name])->values()->all(),
        ])->values()->all();

        $assignmentRows = DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('roles.status', 'active')
            ->where('role_assignments.status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(function ($query) use ($user, $groupIds) {
                $query->where(fn ($direct) => $direct->where('subject_type', 'user')->where('subject_id', $user->id));
                if ($groupIds->isNotEmpty()) {
                    $query->orWhere(fn ($group) => $group->where('subject_type', 'group')->whereIn('subject_id', $groupIds));
                }
            })
            ->select('role_assignments.*', 'roles.code as role_code', 'roles.name as role_name')
            ->get();
        $groupNames = DB::table('groups')->whereIn('id', $assignmentRows->where('subject_type', 'group')->pluck('subject_id'))->pluck('name', 'id');
        $source = fn ($row) => [
            'assignment_id' => $row->id,
            'type' => $row->subject_type === 'user' ? 'direct' : 'group',
            'group_id' => $row->subject_type === 'group' ? $row->subject_id : null,
            'group_name' => $row->subject_type === 'group' ? $groupNames->get($row->subject_id) : null,
            'scope_type' => $row->scope_type,
            'scope_group_id' => $row->scope_group_id,
            'include_descendants' => (bool) $row->include_descendants,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
        ];
        $roles = $assignmentRows->groupBy('role_id')->map(fn (Collection $rows) => [
            'code' => $rows->first()->role_code,
            'name' => $rows->first()->role_name,
            'sources' => $rows->map($source)->values()->all(),
        ])->values()->all();

        $permissionRows = DB::table('permissions')
            ->join('permission_role', 'permission_role.permission_id', '=', 'permissions.id')
            ->join('permission_scope_types', 'permission_scope_types.permission_id', '=', 'permissions.id')
            ->whereIn('permission_role.role_id', $assignmentRows->pluck('role_id'))
            ->select('permissions.id', 'permissions.code', 'permissions.description', 'permission_role.role_id', 'permission_scope_types.scope_type')
            ->get();
        $permissions = $permissionRows->groupBy('id')->map(function (Collection $rows) use ($assignmentRows, $source) {
            $sources = $assignmentRows
                ->whereIn('role_id', $rows->pluck('role_id'))
                ->filter(fn ($assignment) => $rows->where('role_id', $assignment->role_id)->contains('scope_type', $assignment->scope_type));

            return [
                'code' => $rows->first()->code,
                'description' => $rows->first()->description,
                'sources' => $sources->map(fn ($assignment) => $source($assignment) + ['role_code' => $assignment->role_code, 'role_name' => $assignment->role_name])->values()->all(),
            ];
        })->filter(fn (array $permission) => $permission['sources'] !== [])->values()->all();

        return compact('features', 'roles', 'permissions');
    }

    /** @return Collection<int, string> */
    public function features(User $user): Collection
    {
        $now = now();
        $assigned = DB::table('features')->join('group_feature_assignments', 'features.id', '=', 'group_feature_assignments.feature_id')
            ->join('memberships', 'group_feature_assignments.group_id', '=', 'memberships.group_id')
            ->join('groups', 'memberships.group_id', '=', 'groups.id')->where('memberships.user_id', $user->id)
            ->where('features.status', 'active')->where('groups.status', 'active')->select('features.id', 'features.code', 'features.parent_feature_id')->get();
        $suspendedIds = DB::table('user_feature_suspensions')
            ->where('user_feature_suspensions.user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))->pluck('feature_id');
        $effectiveRows = $assigned->reject(fn ($feature) => $suspendedIds->contains($feature->id) || ($feature->parent_feature_id !== null && $suspendedIds->contains($feature->parent_feature_id)));
        $parentCodes = DB::table('features')->whereIn('id', $effectiveRows->pluck('parent_feature_id')->filter())->pluck('code');

        return $effectiveRows->pluck('code')->merge($parentCodes)->unique()->sort()->values();
    }

    /** @return Collection<int, string> */
    public function permissions(User $user): Collection
    {
        $now = now();
        $groupIds = DB::table('memberships')->join('groups', 'memberships.group_id', '=', 'groups.id')
            ->where('memberships.user_id', $user->id)->where('groups.status', 'active')->pluck('groups.id');

        return DB::table('permissions')->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')->join('role_assignments', 'roles.id', '=', 'role_assignments.role_id')
            ->join('permission_scope_types', function ($join) {
                $join->on('permission_scope_types.permission_id', '=', 'permissions.id')
                    ->on('permission_scope_types.scope_type', '=', 'role_assignments.scope_type');
            })
            ->where('roles.status', 'active')->where('role_assignments.status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(function ($q) use ($user, $groupIds) {
                $q->where(fn ($u) => $u->where('subject_type', 'user')->where('subject_id', $user->id));
                if ($groupIds->isNotEmpty()) {
                    $q->orWhere(fn ($g) => $g->where('subject_type', 'group')->whereIn('subject_id', $groupIds));
                }
            })->pluck('permissions.code')->unique()->sort()->values();
    }

    public function hasFeature(User $user, string $feature): bool
    {
        return $this->features($user)->contains($feature);
    }

    /** @return Collection<int, string> */
    public function roles(User $user): Collection
    {
        $now = now();
        $groupIds = DB::table('memberships')
            ->join('groups', 'memberships.group_id', '=', 'groups.id')
            ->where('memberships.user_id', $user->id)
            ->where('groups.status', 'active')
            ->pluck('groups.id');

        return DB::table('role_assignments')
            ->join('roles', 'role_assignments.role_id', '=', 'roles.id')
            ->where('roles.status', 'active')
            ->where('role_assignments.status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(function ($query) use ($user, $groupIds): void {
                $query->where(fn ($direct) => $direct->where('subject_type', 'user')->where('subject_id', $user->id));
                if ($groupIds->isNotEmpty()) {
                    $query->orWhere(fn ($group) => $group->where('subject_type', 'group')->whereIn('subject_id', $groupIds));
                }
            })
            ->pluck('roles.code')
            ->unique()
            ->sort()
            ->values();
    }

    public function hasRole(User $user, string $role): bool
    {
        return $this->roles($user)->contains($role);
    }

    public function hasPermission(User $user, string $permission, ?string $resourceGroupId = null, ?string $resourceUserId = null): bool
    {
        $now = now();
        $groupIds = DB::table('memberships')->join('groups', 'memberships.group_id', '=', 'groups.id')
            ->where('memberships.user_id', $user->id)->where('groups.status', 'active')->pluck('groups.id');
        $assignments = DB::table('role_assignments')->join('roles', 'role_assignments.role_id', '=', 'roles.id')
            ->join('permission_role', 'roles.id', '=', 'permission_role.role_id')->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->join('permission_scope_types', function ($join) {
                $join->on('permission_scope_types.permission_id', '=', 'permissions.id')
                    ->on('permission_scope_types.scope_type', '=', 'role_assignments.scope_type');
            })
            ->where('permissions.code', $permission)->where('roles.status', 'active')->where('role_assignments.status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(function ($q) use ($user, $groupIds) {
                $q->where(fn ($u) => $u->where('subject_type', 'user')->where('subject_id', $user->id));
                if ($groupIds->isNotEmpty()) {
                    $q->orWhere(fn ($g) => $g->where('subject_type', 'group')->whereIn('subject_id', $groupIds));
                }
            })->select('role_assignments.*')->get();

        return $assignments->contains(function ($assignment) use ($user, $resourceGroupId, $resourceUserId): bool {
            if ($assignment->scope_type === 'global') {
                return true;
            }
            // 実際に担当している承認タスクかは各申請Controllerが別途検証する。
            // APPROVAL_TASKはここでは「承認操作を行える」ゲートだけを表す。
            if ($assignment->scope_type === 'approval_task') {
                return true;
            }
            if ($assignment->scope_type === 'self') {
                return $resourceUserId !== null && $resourceUserId === $user->id;
            }
            if ($assignment->scope_type !== 'group' || $assignment->scope_group_id === null) {
                return false;
            }
            $candidateGroupIds = $resourceGroupId !== null
                ? collect([$resourceGroupId])
                : ($resourceUserId !== null ? DB::table('memberships')->where('user_id', $resourceUserId)->pluck('group_id') : collect());
            foreach ($candidateGroupIds as $candidateGroupId) {
                if ($candidateGroupId === $assignment->scope_group_id) {
                    return true;
                }
                if (! $assignment->include_descendants) {
                    continue;
                }
                $parent = DB::table('groups')->where('id', $candidateGroupId)->value('parent_group_id');
                while ($parent !== null) {
                    if ($parent === $assignment->scope_group_id) {
                        return true;
                    }
                    $parent = DB::table('groups')->where('id', $parent)->value('parent_group_id');
                }
            }

            return false;
        });
    }

    public function hasGlobalPermission(User $user, string $permission): bool
    {
        return $this->activeAssignmentsFor($user, $permission)->contains('scope_type', 'global');
    }

    /** @return Collection<int, string> */
    public function permittedGroupIds(User $user, string $permission): Collection
    {
        $result = collect();
        foreach ($this->activeAssignmentsFor($user, $permission)->where('scope_type', 'group') as $assignment) {
            if ($assignment->scope_group_id === null) {
                continue;
            }
            $result->push($assignment->scope_group_id);
            if (! $assignment->include_descendants) {
                continue;
            }
            $frontier = collect([$assignment->scope_group_id]);
            while ($frontier->isNotEmpty()) {
                $children = DB::table('groups')->whereIn('parent_group_id', $frontier)->pluck('id');
                $result = $result->merge($children);
                $frontier = $children;
            }
        }

        return $result->unique()->values();
    }

    /**
     * global スコープで指定Permissionを保有するユーザーのID一覧
     * (承認者選択(UserPicker)の絞り込み等、軽量な用途向け)。
     * グループスコープの割当は対象外とする(グローバルに絞り込む用途に限定するため)。
     *
     * @return Collection<int, string>
     */
    public function userIdsWithGlobalPermission(string $permission): Collection
    {
        $now = now();

        $assignments = DB::table('role_assignments')
            ->join('roles', 'role_assignments.role_id', '=', 'roles.id')
            ->join('permission_role', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->join('permission_scope_types', function ($join) {
                $join->on('permission_scope_types.permission_id', '=', 'permissions.id')
                    ->on('permission_scope_types.scope_type', '=', 'role_assignments.scope_type');
            })
            ->where('permissions.code', $permission)
            ->where('role_assignments.scope_type', 'global')
            ->where('roles.status', 'active')
            ->where('role_assignments.status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->select('role_assignments.subject_type', 'role_assignments.subject_id')
            ->get();

        $directUserIds = $assignments->where('subject_type', 'user')->pluck('subject_id');
        $groupIds = $assignments->where('subject_type', 'group')->pluck('subject_id');
        $groupMemberIds = $groupIds->isNotEmpty()
            ? DB::table('memberships')->whereIn('group_id', $groupIds)->pluck('user_id')
            : collect();

        return $directUserIds->merge($groupMemberIds)->unique()->values();
    }

    private function activeAssignmentsFor(User $user, string $permission): Collection
    {
        $now = now();
        $groupIds = DB::table('memberships')->join('groups', 'memberships.group_id', '=', 'groups.id')
            ->where('memberships.user_id', $user->id)->where('groups.status', 'active')->pluck('groups.id');

        return DB::table('role_assignments')->join('roles', 'role_assignments.role_id', '=', 'roles.id')
            ->join('permission_role', 'roles.id', '=', 'permission_role.role_id')->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->join('permission_scope_types', function ($join) {
                $join->on('permission_scope_types.permission_id', '=', 'permissions.id')
                    ->on('permission_scope_types.scope_type', '=', 'role_assignments.scope_type');
            })
            ->where('permissions.code', $permission)->where('roles.status', 'active')->where('role_assignments.status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(function ($q) use ($user, $groupIds) {
                $q->where(fn ($u) => $u->where('subject_type', 'user')->where('subject_id', $user->id));
                if ($groupIds->isNotEmpty()) {
                    $q->orWhere(fn ($g) => $g->where('subject_type', 'group')->whereIn('subject_id', $groupIds));
                }
            })->select('role_assignments.*')->get();
    }
}
