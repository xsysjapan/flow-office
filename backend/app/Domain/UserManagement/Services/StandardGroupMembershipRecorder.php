<?php

namespace App\Domain\UserManagement\Services;

use App\Domain\UserManagement\Aggregates\UserMembershipAggregate;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Facades\DB;

/**
 * Records standard-group membership as domain events.
 *
 * A user registration must not create memberships as an implicit projector side
 * effect: doing so makes the projection impossible to explain from StoredEvents.
 */
final class StandardGroupMembershipRecorder
{
    /** @param list<string> $groupCodes */
    public function add(string $userId, array $groupCodes, string $actorUserId): void
    {
        $groups = DB::table('groups')
            ->whereIn('code', array_values(array_unique($groupCodes)))
            ->where('status', 'active')
            ->pluck('id', 'code');

        if ($groups->isEmpty()) {
            return;
        }

        $existingGroupIds = DB::table('memberships')
            ->where('user_id', $userId)
            ->whereIn('group_id', $groups->values())
            ->pluck('group_id')
            ->all();
        $groups = $groups->reject(fn ($groupId) => in_array($groupId, $existingGroupIds, true));
        if ($groups->isEmpty()) {
            return;
        }

        $aggregate = UserMembershipAggregate::retrieve(
            UserManagementStreamId::for('user-membership', $userId),
        );

        foreach ($groupCodes as $groupCode) {
            $groupId = $groups->get($groupCode);
            if ($groupId !== null) {
                $aggregate->add($userId, $groupId, 'member', false, $actorUserId);
            }
        }

        $aggregate->persist();
    }
}
