<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Role;
use App\Models\SystemSetting;

final class PrivilegeAssignmentPolicy
{
    public function assertSelfAssignmentAllowed(string $actorUserId, string $subjectType, string $subjectId, int $roleId): void
    {
        if (! SystemSetting::current()->prohibit_self_privileged_role_assignment
            || $subjectType !== 'user'
            || $actorUserId !== $subjectId) {
            return;
        }

        $role = Role::query()->with('permissions')->find($roleId);
        $isPrivileged = $role?->code === Role::ADMIN
            || $role?->permissions->pluck('code')->intersect(['user.manage', 'system_settings.update'])->isNotEmpty();

        if ($isPrivileged) {
            throw new DomainRuleException('自分自身へ特権Roleを付与することは企業設定で禁止されています。');
        }
    }
}
