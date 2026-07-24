<?php

namespace App\Domain\User\Projectors;

use App\Domain\LegacyMigration;
use App\Domain\User\Events\UserCreatedFromSsoLogin;
use App\Domain\User\Events\UserHireDateSet;
use App\Domain\User\Events\UserLoggedIn;
use App\Domain\User\Events\UserMigratedFromLegacy;
use App\Domain\User\Events\UserOnboardedAsAdmin;
use App\Domain\User\Events\UserRolesChanged;
use App\Domain\User\Events\UserSsoAccountLinked;
use App\Domain\User\Events\UserSyncedFromMs365;
use App\Domain\User\Events\UserTerminationDateSet;
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

    /** @see LegacyMigration 本番カットオーバー移行専用(docs/30-legacy-data-migration.md)。 */
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
}
