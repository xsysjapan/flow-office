<?php

namespace App\Domain\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 特定の個人に紐づかない通知の宛先を、実効RoleAssignmentから解決する。
 */
class NotificationRecipients
{
    /**
     * @param  array<int, string>  $roleCodes
     * @return Collection<int, User>
     */
    public static function byRoles(array $roleCodes): Collection
    {
        $now = now();
        $assignments = DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->whereIn('roles.code', $roleCodes)
            ->where('roles.status', 'active')
            ->where('role_assignments.status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->select('role_assignments.subject_type', 'role_assignments.subject_id')
            ->get();
        $userIds = $assignments->where('subject_type', 'user')->pluck('subject_id');
        $groupIds = $assignments->where('subject_type', 'group')->pluck('subject_id');
        if ($groupIds->isNotEmpty()) {
            $userIds = $userIds->merge(
                DB::table('memberships')
                    ->join('groups', 'groups.id', '=', 'memberships.group_id')
                    ->whereIn('memberships.group_id', $groupIds)
                    ->where('groups.status', 'active')
                    ->pluck('memberships.user_id'),
            );
        }

        return User::query()->whereIn('id', $userIds->unique())->get();
    }
}
