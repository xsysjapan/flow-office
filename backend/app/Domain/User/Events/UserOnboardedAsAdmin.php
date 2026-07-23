<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.onboarded_as_admin(docs/06-usecases-auth.md UC-000)。行の新規作成自体も
 * UserProjectorが担うため、Eloquentの行構築に必要な属性をすべて持つ。ローカルパスワード
 * モードのパスワードはイベントに含めない(平文パスワードを永続イベントログに残さないため。
 * docs/29-event-sourcing-framework-migration.md参照)。
 */
class UserOnboardedAsAdmin extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $entraUserId,
        public readonly string $name,
        public readonly ?string $email,
        /** 'sso' または 'local'。docs/06-usecases-auth.md UC-000参照。 */
        public readonly string $authMethod,
    ) {}
}
