<?php

namespace App\Domain\UserManagement\Projectors;

use App\Domain\UserManagement\Events\ExternalHrImportApplied;
use App\Domain\UserManagement\Events\ExternalIdentityLinked;
use App\Domain\UserManagement\Events\ExternalIdentityUnlinked;
use App\Domain\UserManagement\Events\GroupCreated;
use App\Domain\UserManagement\Events\GroupTypeCreated;
use App\Domain\UserManagement\Events\GroupTypeUpdated;
use App\Domain\UserManagement\Events\GroupUpdated;
use App\Domain\UserManagement\Events\MembershipAdded;
use App\Domain\UserManagement\Events\MembershipChangeSetApplied;
use App\Domain\UserManagement\Events\MembershipChangeSetCancelled;
use App\Domain\UserManagement\Events\MembershipChangeSetCreated;
use App\Domain\UserManagement\Events\MembershipChangeSetFailed;
use App\Domain\UserManagement\Events\MembershipChangeSetScheduled;
use App\Domain\UserManagement\Events\MembershipChangeSetUpdated;
use App\Domain\UserManagement\Events\MembershipPrimaryChanged;
use App\Domain\UserManagement\Events\MembershipRemoved;
use App\Domain\UserManagement\Events\UserFieldAuthorityChanged;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class UserManagementProjector extends Projector
{
    public function onExternalHrImportApplied(ExternalHrImportApplied $event): void
    {
        foreach ($event->rows as $row) {
            $changes = $row['changes'];
            $attributes = [
                'employee_number' => $changes['employee_number'] ?? null,
                'name' => $changes['display_name'] ?? '',
                'email' => $changes['email'] ?? null,
                'department' => $changes['department'] ?? null,
                'job_title' => $changes['job_title'] ?? null,
                'employment_status' => $changes['employment_status'] ?? 'active',
                'account_status' => $changes['account_status'] ?? 'active',
                'source_type' => 'external_hr',
                'updated_at' => $event->createdAt(),
            ];
            $existing = DB::table('users')->where('id', $row['user_id'])->first();
            if ($existing) {
                $attributes = array_filter(
                    $attributes,
                    fn ($value, $key) => array_key_exists($key === 'name' ? 'display_name' : $key, $changes) || in_array($key, ['source_type', 'updated_at'], true),
                    ARRAY_FILTER_USE_BOTH,
                );
                DB::table('users')->where('id', $row['user_id'])->update($attributes);
            } else {
                $attributes['id'] = $row['user_id'];
                $attributes['timezone'] = SystemSetting::current()->default_timezone;
                $attributes['usage_start_date'] = $event->createdAt()->toDateString();
                $attributes['created_at'] = $event->createdAt();
                DB::table('users')->insert($attributes);
            }
            DB::table('external_identities')->updateOrInsert(
                ['provider' => 'EXTERNAL_HR', 'external_tenant_id' => null, 'external_subject_id' => $row['external_subject_id']],
                ['user_id' => $row['user_id'], 'external_code' => $changes['employee_number'] ?? null, 'email' => $changes['email'] ?? null, 'status' => 'active', 'linked_at' => $event->createdAt(), 'last_synced_at' => $event->createdAt(), 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
            );
        }
    }

    public function onGroupCreated(GroupCreated $event): void
    {
        DB::table('groups')->updateOrInsert(
            ['id' => $event->aggregateRootUuid()],
            ['group_type_id' => $event->groupTypeId, 'name' => $event->name, 'code' => $event->code, 'description' => $event->description, 'parent_group_id' => $event->parentGroupId, 'status' => 'active', 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
    }

    public function onGroupTypeCreated(GroupTypeCreated $event): void
    {
        DB::table('group_types')->updateOrInsert(
            ['id' => $event->groupTypeId],
            ['code' => $event->code, 'name' => $event->name, 'display_order' => $event->displayOrder, 'is_system' => false, 'status' => 'active', 'membership_limit_type' => $event->membershipLimitType, 'max_memberships_per_user' => $event->maxMembershipsPerUser, 'primary_membership_required' => $event->primaryMembershipRequired, 'max_primary_memberships' => $event->maxPrimaryMemberships, 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
    }

    public function onGroupTypeUpdated(GroupTypeUpdated $event): void
    {
        DB::table('group_types')->where('id', $event->groupTypeId)->update(['name' => $event->name, 'display_order' => $event->displayOrder, 'status' => $event->status, 'membership_limit_type' => $event->membershipLimitType, 'max_memberships_per_user' => $event->maxMembershipsPerUser, 'primary_membership_required' => $event->primaryMembershipRequired, 'max_primary_memberships' => $event->maxPrimaryMemberships, 'updated_at' => $event->createdAt()]);
    }

    public function onGroupUpdated(GroupUpdated $event): void
    {
        DB::table('groups')->where('id', $event->aggregateRootUuid())->update(['name' => $event->name, 'code' => $event->code, 'description' => $event->description, 'parent_group_id' => $event->parentGroupId, 'status' => $event->status, 'updated_at' => $event->createdAt()]);
    }

    public function onExternalIdentityLinked(ExternalIdentityLinked $event): void
    {
        DB::table('external_identities')->updateOrInsert(
            ['provider' => $event->provider, 'external_subject_id' => $event->externalSubjectId],
            ['user_id' => $event->userId, 'external_tenant_id' => $event->externalTenantId, 'external_code' => $event->externalCode, 'email' => $event->email, 'status' => 'active', 'linked_at' => $event->createdAt(), 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
        if ($event->provider === 'MICROSOFT_ENTRA') {
            DB::table('users')->where('id', $event->userId)->update(['entra_user_id' => $event->externalSubjectId, 'updated_at' => $event->createdAt()]);
        }
    }

    public function onExternalIdentityUnlinked(ExternalIdentityUnlinked $event): void
    {
        DB::table('external_identities')->where('provider', $event->provider)->where('external_subject_id', $event->externalSubjectId)->update(['status' => 'unlinked', 'updated_at' => $event->createdAt()]);
        if ($event->provider === 'MICROSOFT_ENTRA') {
            DB::table('users')->where('id', $event->userId)->update(['entra_user_id' => null, 'updated_at' => $event->createdAt()]);
        }
    }

    public function onUserFieldAuthorityChanged(UserFieldAuthorityChanged $event): void
    {
        DB::table('field_authorities')->updateOrInsert(
            ['field_key' => $event->fieldKey],
            ['authority_type' => $event->authorityType, 'provider' => $event->provider, 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
    }

    public function onMembershipAdded(MembershipAdded $event): void
    {
        DB::table('memberships')->updateOrInsert(
            ['user_id' => $event->userId, 'group_id' => $event->groupId],
            ['membership_kind' => $event->membershipKind, 'is_primary' => $event->isPrimary, 'created_by' => $event->actorUserId, 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
    }

    public function onMembershipRemoved(MembershipRemoved $event): void
    {
        DB::table('memberships')->where('user_id', $event->userId)->where('group_id', $event->groupId)->delete();
    }

    public function onMembershipPrimaryChanged(MembershipPrimaryChanged $event): void
    {
        DB::table('memberships')->where('user_id', $event->userId)->where('group_id', $event->groupId)->update(['is_primary' => $event->isPrimary, 'membership_kind' => $event->isPrimary ? 'primary' : 'member', 'updated_at' => $event->createdAt()]);
    }

    public function onMembershipChangeSetCreated(MembershipChangeSetCreated $event): void
    {
        $this->replaceChangeSet($event, 'draft');
    }

    public function onMembershipChangeSetScheduled(MembershipChangeSetScheduled $event): void
    {
        $this->replaceChangeSet($event, 'scheduled');
    }

    public function onMembershipChangeSetUpdated(MembershipChangeSetUpdated $event): void
    {
        $status = DB::table('membership_change_sets')->where('id', $event->aggregateRootUuid())->value('status') ?? 'draft';
        $this->replaceChangeSet($event, $status);
    }

    public function onMembershipChangeSetApplied(MembershipChangeSetApplied $event): void
    {
        DB::table('membership_change_sets')->where('id', $event->aggregateRootUuid())->update(['status' => 'applied', 'applied_at' => $event->createdAt(), 'updated_at' => $event->createdAt()]);
    }

    public function onMembershipChangeSetCancelled(MembershipChangeSetCancelled $event): void
    {
        DB::table('membership_change_sets')->where('id', $event->aggregateRootUuid())->update(['status' => 'cancelled', 'cancelled_at' => $event->createdAt(), 'updated_at' => $event->createdAt()]);
    }

    public function onMembershipChangeSetFailed(MembershipChangeSetFailed $event): void
    {
        DB::table('membership_change_sets')->where('id', $event->aggregateRootUuid())->update(['status' => 'failed', 'failure_reason' => $event->reason, 'updated_at' => $event->createdAt()]);
    }

    private function replaceChangeSet(object $event, string $status): void
    {
        DB::table('membership_change_sets')->updateOrInsert(
            ['id' => $event->aggregateRootUuid()],
            ['user_id' => $event->userId, 'effective_at' => $event->effectiveAt, 'source_type' => $event->sourceType, 'status' => $status, 'created_by' => $event->actorUserId, 'note' => $event->note, 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()],
        );
        DB::table('membership_change_items')->where('change_set_id', $event->aggregateRootUuid())->delete();
        foreach ($event->items as $item) {
            DB::table('membership_change_items')->insert(array_merge($item, ['change_set_id' => $event->aggregateRootUuid(), 'created_at' => $event->createdAt(), 'updated_at' => $event->createdAt()]));
        }
    }
}
