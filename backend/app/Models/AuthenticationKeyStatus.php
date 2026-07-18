<?php

namespace App\Models;

/**
 * authentication_keys.status。docs/24-usecases-authentication-keys.md参照。
 */
final class AuthenticationKeyStatus
{
    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const DISABLED = 'disabled';
}
