<?php

namespace App\Domain\AccessControl\Projectors;

use App\Domain\AccessControl\Events\FeatureAssignedToGroup;
use App\Domain\AccessControl\Events\FeatureRemovedFromGroup;
use App\Domain\AccessControl\Events\RoleAssignmentCreated;
use App\Domain\AccessControl\Events\RoleAssignmentRemoved;
use App\Domain\AccessControl\Events\RoleAssignmentUpdated;
use App\Domain\AccessControl\Events\RoleCreated;
use App\Domain\AccessControl\Events\RolePermissionsChanged;
use App\Domain\AccessControl\Events\RoleUpdated;
use App\Domain\AccessControl\Events\UserFeatureSuspended;
use App\Domain\AccessControl\Events\UserFeatureSuspensionRemoved;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AccessControlProjector extends Projector
{
    public function onFeatureAssignedToGroup(FeatureAssignedToGroup $event): void
    {
        DB::table('group_feature_assignments')->updateOrInsert(
            ['group_id' => $event->groupId, 'feature_id' => $event->featureId],
            ['assigned_by' => $event->actorUserId, 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
    }

    public function onFeatureRemovedFromGroup(FeatureRemovedFromGroup $event): void
    {
        DB::table('group_feature_assignments')->where('group_id', $event->groupId)->where('feature_id', $event->featureId)->delete();
    }

    public function onUserFeatureSuspended(UserFeatureSuspended $event): void
    {
        DB::table('user_feature_suspensions')->updateOrInsert(
            ['id' => $event->suspensionId],
            ['user_id' => $event->userId, 'feature_id' => $event->featureId, 'reason' => $event->reason, 'starts_at' => $this->databaseDate($event->startsAt), 'ends_at' => $this->databaseDate($event->endsAt), 'created_by' => $event->actorUserId, 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
    }

    public function onUserFeatureSuspensionRemoved(UserFeatureSuspensionRemoved $event): void
    {
        DB::table('user_feature_suspensions')->where('id', $event->suspensionId)->where('user_id', $event->userId)->delete();
    }

    public function onRoleCreated(RoleCreated $event): void
    {
        DB::table('roles')->updateOrInsert(
            ['id' => $event->roleId],
            ['code' => $event->code, 'name' => $event->name, 'description' => $event->description, 'is_system' => false, 'status' => 'active', 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
    }

    public function onRoleUpdated(RoleUpdated $event): void
    {
        DB::table('roles')->where('id', $event->roleId)->update(['name' => $event->name, 'description' => $event->description, 'status' => $event->status, 'updated_at' => $event->createdAt()]);
    }

    public function onRolePermissionsChanged(RolePermissionsChanged $event): void
    {
        DB::table('permission_role')->where('role_id', $event->roleId)->delete();
        foreach ($event->permissionIds as $permissionId) {
            DB::table('permission_role')->insert(['role_id' => $event->roleId, 'permission_id' => $permissionId]);
        }
    }

    public function onRoleAssignmentCreated(RoleAssignmentCreated $event): void
    {
        DB::table('role_assignments')->updateOrInsert(
            ['id' => $event->aggregateRootUuid()],
            ['subject_type' => $event->subjectType, 'subject_id' => $event->subjectId, 'role_id' => $event->roleId, 'scope_type' => $event->scopeType, 'scope_group_id' => $event->scopeGroupId, 'include_descendants' => $event->includeDescendants, 'starts_at' => $this->databaseDate($event->startsAt), 'ends_at' => $this->databaseDate($event->endsAt), 'status' => 'active', 'assigned_by' => $event->actorUserId, 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
    }

    public function onRoleAssignmentUpdated(RoleAssignmentUpdated $event): void
    {
        DB::table('role_assignments')->where('id', $event->aggregateRootUuid())->update(['scope_type' => $event->scopeType, 'scope_group_id' => $event->scopeGroupId, 'include_descendants' => $event->includeDescendants, 'starts_at' => $this->databaseDate($event->startsAt), 'ends_at' => $this->databaseDate($event->endsAt), 'updated_at' => $event->createdAt()]);
    }

    public function onRoleAssignmentRemoved(RoleAssignmentRemoved $event): void
    {
        DB::table('role_assignments')->where('id', $event->aggregateRootUuid())->update(['status' => 'removed', 'updated_at' => $event->createdAt()]);
    }

    private function databaseDate(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
