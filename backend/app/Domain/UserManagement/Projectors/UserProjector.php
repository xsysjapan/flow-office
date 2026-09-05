<?php

namespace App\Domain\UserManagement\Projectors;

use App\Domain\UserManagement\Events\UserCreatedFromSsoLogin;
use App\Domain\UserManagement\Events\UserCreatedManually;
use App\Domain\UserManagement\Events\UserHireDateSet;
use App\Domain\UserManagement\Events\UserLoggedIn;
use App\Domain\UserManagement\Events\PaidLeaveAutoGrantEnabledSet;
use App\Domain\UserManagement\Events\SpecialLeaveAutoGrantEnabledSet;
use App\Domain\UserManagement\Events\UserMigratedFromLegacy;
use App\Domain\UserManagement\Events\UserOnboardedAsAdmin;
use App\Domain\UserManagement\Events\UserProfileUpdated;
use App\Domain\UserManagement\Events\UserSsoAccountLinked;
use App\Domain\UserManagement\Events\UserSyncedFromMs365;
use App\Domain\UserManagement\Events\UserTerminationDateSet;
use App\Domain\UserManagement\Events\UserUsageStartDateSet;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * user.*イベントからusersを作成・更新し、新規ユーザーを標準グループへ所属させる。
 */
class UserProjector extends Projector
{
    public function onUserCreatedManually(UserCreatedManually $event): void
    {
        $user = User::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [...$event->attributes, 'source_type' => 'local', 'timezone' => SystemSetting::current()->default_timezone,
                'usage_start_date' => $event->createdAt()->toDateString()],
        );

    }

    public function onUserProfileUpdated(UserProfileUpdated $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update($event->after);
    }

    public function onUserOnboardedAsAdmin(UserOnboardedAsAdmin $event): void
    {
        $user = User::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'entra_user_id' => $event->entraUserId,
                'name' => $event->name,
                'email' => $event->email,
                'employment_status' => 'active',
                'account_status' => 'active',
                'source_type' => $event->entraUserId !== null ? 'microsoft_entra' : 'local',
                'timezone' => SystemSetting::current()->default_timezone,
                // usage_start_date未設定のまま在籍月より前の督促が誤送信されるのを防ぐため、
                // 新規作成時はイベント記録日をデフォルト設定する。管理者は後から上書き可能。
                'usage_start_date' => $event->createdAt()->toDateString(),
            ],
        );

        if ($event->entraUserId !== null) {
            $this->linkEntraIdentity($user->id, $event->entraUserId, $event->email, $event->createdAt());
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
                'account_status' => 'active',
                'source_type' => 'microsoft_entra',
                'timezone' => SystemSetting::current()->default_timezone,
                // usage_start_date未設定のまま在籍月より前の督促が誤送信されるのを防ぐため、
                // 新規作成時はイベント記録日をデフォルト設定する。管理者は後から上書き可能。
                'usage_start_date' => $event->createdAt()->toDateString(),
            ],
        );

        $this->linkEntraIdentity($user->id, $event->entraUserId, $event->email, $event->createdAt());
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
                'account_status' => $existing->account_status ?? 'active',
                'source_type' => $existing->source_type ?? 'microsoft_entra',
                // timezoneはMS365に存在する属性ではないため、新規作成時のみシステム設定の
                // デフォルトを設定し、既存行のtimezoneは上書きしない。
                'timezone' => $existing->timezone ?? SystemSetting::current()->default_timezone,
                // usage_start_dateも同様に、新規作成時のみイベント記録日をデフォルト設定し、
                // 既存行(手動設定済みの可能性がある)は絶対に上書きしない。
                'usage_start_date' => $existing->usage_start_date ?? $event->createdAt()->toDateString(),
            ],
        );
        $this->linkEntraIdentity($event->aggregateRootUuid(), $event->entraUserId, $event->email, $event->createdAt());
    }

    public function onUserSsoAccountLinked(UserSsoAccountLinked $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'entra_user_id' => $event->entraUserId,
        ]);
        $user = User::query()->find($event->aggregateRootUuid());
        $this->linkEntraIdentity($event->aggregateRootUuid(), $event->entraUserId, $user?->email, $event->createdAt());
    }

    private function linkEntraIdentity(string $userId, string $subjectId, ?string $email, mixed $occurredAt): void
    {
        DB::table('external_identities')->updateOrInsert(
            ['provider' => 'MICROSOFT_ENTRA', 'external_subject_id' => $subjectId],
            ['user_id' => $userId, 'external_tenant_id' => SystemSetting::current()->m365_tenant_id, 'email' => $email, 'status' => 'active', 'linked_at' => $occurredAt, 'last_synced_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt],
        );
    }

    public function onUserLoggedIn(UserLoggedIn $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'last_login_at' => $event->loggedInAt,
        ]);
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

    public function onPaidLeaveAutoGrantEnabledSet(PaidLeaveAutoGrantEnabledSet $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'paid_leave_auto_grant_enabled' => $event->enabled,
        ]);
    }

    public function onSpecialLeaveAutoGrantEnabledSet(SpecialLeaveAutoGrantEnabledSet $event): void
    {
        User::query()->whereKey($event->aggregateRootUuid())->update([
            'special_leave_auto_grant_enabled' => $event->enabled,
        ]);
    }
}
