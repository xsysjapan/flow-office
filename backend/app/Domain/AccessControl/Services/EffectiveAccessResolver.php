<?php

namespace App\Domain\AccessControl\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EffectiveAccessResolver
{
    /** @return Collection<int, string> */
    public function features(User $user): Collection
    {
        $now = now();
        $assigned = DB::table('features')->join('group_feature_assignments', 'features.id', '=', 'group_feature_assignments.feature_id')
            ->join('memberships', 'group_feature_assignments.group_id', '=', 'memberships.group_id')
            ->join('groups', 'memberships.group_id', '=', 'groups.id')->where('memberships.user_id', $user->id)
            ->where('features.status', 'active')->where('groups.status', 'active')->pluck('features.code');
        $suspended = DB::table('features')->join('user_feature_suspensions', 'features.id', '=', 'user_feature_suspensions.feature_id')
            ->where('user_feature_suspensions.user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))->pluck('features.code');

        return $assigned->diff($suspended)->unique()->values();
    }

    /** @return Collection<int, string> */
    public function permissions(User $user): Collection
    {
        $now = now();
        $groupIds = DB::table('memberships')->join('groups', 'memberships.group_id', '=', 'groups.id')
            ->where('memberships.user_id', $user->id)->where('groups.status', 'active')->pluck('groups.id');

        return DB::table('permissions')->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')->join('role_assignments', 'roles.id', '=', 'role_assignments.role_id')
            ->where('roles.status', 'active')->where('role_assignments.status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(function ($q) use ($user, $groupIds) {
                $q->where(fn ($u) => $u->where('subject_type', 'user')->where('subject_id', $user->id));
                if ($groupIds->isNotEmpty()) {
                    $q->orWhere(fn ($g) => $g->where('subject_type', 'group')->whereIn('subject_id', $groupIds));
                }
            })->pluck('permissions.code')->unique()->values();
    }

    public function hasFeature(User $user, string $feature): bool
    {
        return $this->features($user)->contains($feature);
    }

    public function hasPermission(User $user, string $permission, ?string $resourceGroupId = null, ?string $resourceUserId = null): bool
    {
        $now = now();
        $groupIds = DB::table('memberships')->join('groups', 'memberships.group_id', '=', 'groups.id')
            ->where('memberships.user_id', $user->id)->where('groups.status', 'active')->pluck('groups.id');
        $assignments = DB::table('role_assignments')->join('roles', 'role_assignments.role_id', '=', 'roles.id')
            ->join('permission_role', 'roles.id', '=', 'permission_role.role_id')->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
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

    private function activeAssignmentsFor(User $user, string $permission): Collection
    {
        $now = now();
        $groupIds = DB::table('memberships')->join('groups', 'memberships.group_id', '=', 'groups.id')
            ->where('memberships.user_id', $user->id)->where('groups.status', 'active')->pluck('groups.id');

        return DB::table('role_assignments')->join('roles', 'role_assignments.role_id', '=', 'roles.id')
            ->join('permission_role', 'roles.id', '=', 'permission_role.role_id')->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
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
