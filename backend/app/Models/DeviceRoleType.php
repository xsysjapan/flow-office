<?php

namespace App\Models;

/**
 * device_roles.role_type。1端末に複数設定できる。docs/23-usecases-devices.md参照。
 */
final class DeviceRoleType
{
    public const ATTENDANCE_READER = 'attendance_reader';

    public const AUTHENTICATION_DEVICE = 'authentication_device';

    public const ACCESS_CONTROL = 'access_control';

    public const PERSONAL_OPERATION = 'personal_operation';

    public const ADMIN_OPERATION = 'admin_operation';

    public const EXTERNAL_EVENT_SOURCE = 'external_event_source';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::ATTENDANCE_READER, self::AUTHENTICATION_DEVICE, self::ACCESS_CONTROL,
            self::PERSONAL_OPERATION, self::ADMIN_OPERATION, self::EXTERNAL_EVENT_SOURCE,
        ];
    }

    /**
     * この役割を持つ端末に発行するSanctumトークンのability。
     *
     * @return array<int, string>
     */
    public static function abilitiesFor(string $roleType): array
    {
        return match ($roleType) {
            self::ATTENDANCE_READER => ['recorder:punch'],
            self::PERSONAL_OPERATION => ['punch:self'],
            self::ADMIN_OPERATION => ['*'],
            default => [],
        };
    }
}
