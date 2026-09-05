<?php

namespace App\Domain\UserManagement\Aggregates;

use App\Domain\UserManagement\Events\PaidLeaveAutoGrantEnabledSet;
use App\Domain\UserManagement\Events\SpecialLeaveAutoGrantEnabledSet;
use App\Domain\UserManagement\Events\UserCreatedFromSsoLogin;
use App\Domain\UserManagement\Events\UserCreatedManually;
use App\Domain\UserManagement\Events\UserHireDateSet;
use App\Domain\UserManagement\Events\UserLoggedIn;
use App\Domain\UserManagement\Events\UserOnboardedAsAdmin;
use App\Domain\UserManagement\Events\UserProfileUpdated;
use App\Domain\UserManagement\Events\UserSsoAccountLinked;
use App\Domain\UserManagement\Events\UserSyncedFromMs365;
use App\Domain\UserManagement\Events\UserTerminationDateSet;
use App\Domain\UserManagement\Events\UserUsageStartDateSet;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * user集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体もUserProjectorに委ねられる。
 * 業務ルール判定(メール・Entra ID重複チェック等)はHandlerがEloquent Projectionの
 * 現在値を読んで行う(他ドメインと同じ理由。テストがUser::factory()->create()で
 * イベントを経由せず直接rowを作成することが極めて多いため、集約の再生状態は信頼できない)。
 */
class UserAggregate extends AggregateRoot
{
    public function createManually(array $attributes, string $createdByUserId): self
    {
        $this->recordThat(new UserCreatedManually($attributes, $createdByUserId));

        return $this;
    }

    public function onboardAsAdmin(?string $entraUserId, string $name, ?string $email, string $authMethod): self
    {
        $this->recordThat(new UserOnboardedAsAdmin(
            entraUserId: $entraUserId,
            name: $name,
            email: $email,
            authMethod: $authMethod,
        ));

        return $this;
    }

    public function createFromSsoLogin(string $entraUserId, string $name, string $email): self
    {
        $this->recordThat(new UserCreatedFromSsoLogin(entraUserId: $entraUserId, name: $name, email: $email));

        return $this;
    }

    public function syncFromMs365(
        string $entraUserId,
        string $name,
        ?string $email,
        ?string $department,
        ?string $jobTitle,
        string $employmentStatus,
    ): self {
        $this->recordThat(new UserSyncedFromMs365(
            entraUserId: $entraUserId,
            name: $name,
            email: $email,
            department: $department,
            jobTitle: $jobTitle,
            employmentStatus: $employmentStatus,
        ));

        return $this;
    }

    public function linkSsoAccount(string $entraUserId): self
    {
        $this->recordThat(new UserSsoAccountLinked(entraUserId: $entraUserId));

        return $this;
    }

    public function recordLogin(bool $wasFirstLogin, string $loggedInAt): self
    {
        $this->recordThat(new UserLoggedIn(wasFirstLogin: $wasFirstLogin, loggedInAt: $loggedInAt));

        return $this;
    }

    public function setHireDate(string $hireDate, string $changedByUserId): self
    {
        $this->recordThat(new UserHireDateSet(hireDate: $hireDate, changedByUserId: $changedByUserId));

        return $this;
    }

    public function setTerminationDate(?string $terminationDate, string $changedByUserId): self
    {
        $this->recordThat(new UserTerminationDateSet(terminationDate: $terminationDate, changedByUserId: $changedByUserId));

        return $this;
    }

    public function setUsageStartDate(string $usageStartDate, string $changedByUserId): self
    {
        $this->recordThat(new UserUsageStartDateSet(usageStartDate: $usageStartDate, changedByUserId: $changedByUserId));

        return $this;
    }

    public function setPaidLeaveAutoGrantEnabled(bool $enabled, string $changedByUserId): self
    {
        $this->recordThat(new PaidLeaveAutoGrantEnabledSet(enabled: $enabled, changedByUserId: $changedByUserId));

        return $this;
    }

    public function setSpecialLeaveAutoGrantEnabled(bool $enabled, string $changedByUserId): self
    {
        $this->recordThat(new SpecialLeaveAutoGrantEnabledSet(enabled: $enabled, changedByUserId: $changedByUserId));

        return $this;
    }

    public function updateProfile(array $before, array $after, string $changedByUserId): self
    {
        $this->recordThat(new UserProfileUpdated($before, $after, $changedByUserId));

        return $this;
    }
}
