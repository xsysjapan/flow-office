<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-004: ローカルパスワードでログイン中のユーザーが、任意のタイミングで自分の
 * アカウントにMicrosoft 365(Entra ID)アカウントを紐づける(docs/06-usecases-auth.md)。
 * 紐づけ後もローカルパスワードでのログインは引き続き使える。
 */
class LinkSsoAccount implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $entraUserId,
    ) {}
}
