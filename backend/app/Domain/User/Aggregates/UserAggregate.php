<?php

namespace App\Domain\User\Aggregates;

use App\Domain\User\Events\UserCreatedFromSsoLogin;
use App\Domain\User\Events\UserHireDateSet;
use App\Domain\User\Events\UserLoggedIn;
use App\Domain\User\Events\UserOnboardedAsAdmin;
use App\Domain\User\Events\UserRolesChanged;
use App\Domain\User\Events\UserSsoAccountLinked;
use App\Domain\User\Events\UserSyncedFromMs365;
use App\Domain\User\Events\UserTerminationDateSet;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * user集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体もUserProjectorに委ねられる。
 * 業務ルール判定(メール・Entra ID重複チェック等)はHandlerがEloquent Projectionの
 * 現在値を読んで行う(他ドメインと同じ理由。テストがUser::factory()->create()で
 * イベントを経由せず直接rowを作成することが極めて多いため、集約の再生状態は信頼できない)。
 */
class UserAggregate extends AggregateRoot
{
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

    /**
     * @param  array<int, string>  $previousRoleCodes
     * @param  array<int, string>  $newRoleCodes
     */
    public function changeRoles(array $previousRoleCodes, array $newRoleCodes, string $changedByUserId): self
    {
        $this->recordThat(new UserRolesChanged(
            previousRoleCodes: $previousRoleCodes,
            newRoleCodes: $newRoleCodes,
            changedByUserId: $changedByUserId,
        ));

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
}
