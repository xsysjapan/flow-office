<?php

namespace App\Domain\User\Projectors;

use App\Domain\User\Events\UserCreatedFromSsoLogin;
use App\Domain\User\Events\UserHireDateSet;
use App\Domain\User\Events\UserLoggedIn;
use App\Domain\User\Events\UserMigratedFromLegacy;
use App\Domain\User\Events\UserOnboardedAsAdmin;
use App\Domain\User\Events\UserRolesChanged;
use App\Domain\User\Events\UserRolesMigratedFromLegacy;
use App\Domain\User\Events\UserSsoAccountLinked;
use App\Domain\User\Events\UserSyncedFromMs365;
use App\Domain\User\Events\UserTerminationDateSet;
use App\Domain\User\Events\UserUsageStartDateSet;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * user.*イベントから users を作成・更新する。ロール(role_user)もこのProjectorが担う
 * (Handlerが直接attach/syncすると、usersテーブルと同様にイベントから再現できなくなるため)。
 */
class UserProjector extends Projector
{
    public function onUserOnboardedAsAdmin(UserOnboardedAsAdmin $event): void
    {
        $user = User::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'entra_user_id' => $event->entraUserId,
                'name' => $event->name,
                'email' => $event->email,
                'employment_status' => 'active',
                'timezone' => SystemSetting::current()->default_timezone,
                // usage_start_date未設定のまま在籍月より前の督促が誤送信されるのを防ぐため、
                // 新規作成時はイベント記録日をデフォルト設定する。管理者は後から上書き可能。
                'usage_start_date' => $event->createdAt()->toDateString(),
            ],
        );

        $adminRole = Role::query()->where('code', Role::ADMIN)->first();
        if ($adminRole !== null) {
            $user->roles()->sync([$adminRole->id]);
        }
    }

    public function onUserCreatedFromSsoLogin(UserCreatedFromSsoLogin $event): void
    {
        $user = User::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'entra_user_id' => $event->entraUserId,
                'name' => $event->name,
                'email' => $event->email,
                'employment_status' => 'active',
                'timezone' => SystemSetting::current()->default_timezone,
                // usage_start_date未設定のまま在籍月より前の督促が誤送信されるのを防ぐため、
                // 新規作成時はイベント記録日をデフォルト設定する。管理者は後から上書き可能。
                'usage_start_date' => $event->createdAt()->toDateString(),
            ],
        );

        $employeeRole = Role::query()->where('code', Role::EMPLOYEE)->first();
        if ($employeeRole !== null) {
            $user->roles()->sync([$employeeRole->id]);
        }
    }

    public function onUserSyncedFromMs365(UserSyncedFromMs365 $event): void
    {
        $existing = User::query()->find($event->aggregateRootUuid());

        User::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'entra_user_id' => $event->entraUserId,
                'name' => $event->name,
                'email' => $event->email,
                'department' => $event->department,
                'job_title' => $event->jobTitle,
                'employment_status' => $event->employmentStatus,
                // timezoneはMS365に存在する属性ではないため、新規作成時のみシステム設定の
                // デフォルトを設定し、既存行のtimezoneは上書きしない。
                'timezone' => $existing->timezone ?? SystemSetting::current()->default_timezone,
                // usage_start_dateも同様に、新規作成時のみイベント記録日をデフォルト設定し、
                // 既存行(手動設定済みの可能性がある)は絶対に上書きしない。
                'usage_start_date' => $existing->usage_start_date ?? $event->createdAt()->toDateString(),
            ],
        );
    }

    public function onUserSsoAccountLinked(UserSsoAccountLinked $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'entra_user_id' => $event->entraUserId,
        ]);
    }

    public function onUserLoggedIn(UserLoggedIn $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'last_login_at' => $event->loggedInAt,
        ]);
    }

    public function onUserRolesChanged(UserRolesChanged $event): void
    {
        $user = User::query()->find($event->aggregateRootUuid());
        if ($user === null) {
            return;
        }

        $roleIds = Role::query()->whereIn('code', $event->newRoleCodes)->pluck('id');
        $user->roles()->sync($roleIds);
    }

    public function onUserRolesMigratedFromLegacy(UserRolesMigratedFromLegacy $event): void
    {
        $user = User::query()->find($event->aggregateRootUuid());
        if ($user === null) {
            return;
        }

        $roleIds = Role::query()->whereIn('code', $event->roleCodes)->pluck('id');
        $user->roles()->sync($roleIds);
    }

    /** 本番カットオーバー移行時にusers行を初期状態として記録した合成イベントの再生用。 */
    public function onUserMigratedFromLegacy(UserMigratedFromLegacy $event): void
    {
        User::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            $event->attributes,
        );
    }

    public function onUserHireDateSet(UserHireDateSet $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'hire_date' => $event->hireDate,
        ]);
    }

    public function onUserTerminationDateSet(UserTerminationDateSet $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'termination_date' => $event->terminationDate,
        ]);
    }

    public function onUserUsageStartDateSet(UserUsageStartDateSet $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'usage_start_date' => $event->usageStartDate,
        ]);
    }
}
