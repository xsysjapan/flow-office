<?php

namespace App\Models;

/**
 * devices.status。docs/23-usecases-devices.md参照。
 */
final class DeviceStatus
{
    public const PENDING_PAIRING = 'pending_pairing';

    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    public const REVOKED = 'revoked';
}
