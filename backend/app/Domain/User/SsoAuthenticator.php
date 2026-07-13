<?php

namespace App\Domain\User;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\RecordSsoLogin;
use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * UC-001: Microsoft SSOでログインする。
 * SocialiteのユーザーオブジェクトからCommandに変換し、CommandBus経由でRecordSsoLoginHandler
 * に処理を委ねる(状態変更をCommandHandlerの外で行わないため。docs/03-architecture.md 3.2)。
 */
class SsoAuthenticator
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function handle(SocialiteUser $ssoUser): User
    {
        return $this->commandBus->dispatch(new RecordSsoLogin(
            entraUserId: $ssoUser->getId(),
            name: $ssoUser->getName() ?? $ssoUser->getNickname() ?? $ssoUser->getEmail(),
            email: $ssoUser->getEmail(),
        ));
    }
}
