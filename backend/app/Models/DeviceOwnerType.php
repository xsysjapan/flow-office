<?php

namespace App\Models;

/**
 * devices.owner_type。docs/23-usecases-devices.md参照。
 */
final class DeviceOwnerType
{
    public const ORGANIZATION_SHARED = 'organization_shared';

    public const PERSONAL = 'personal';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [self::ORGANIZATION_SHARED, self::PERSONAL];
    }
}
