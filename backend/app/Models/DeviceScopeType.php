<?php

namespace App\Models;

/**
 * device_scopes.scope。外部端末に個別付与するAPIスコープ(docs/23-usecases-devices.md
 * UC-D004「外部端末を登録する」)。月次締めや管理者補正等の管理系APIはここに含めない
 * (最小権限)。
 */
final class DeviceScopeType
{
    public const ATTENDANCE_CLOCK = 'attendance:clock';

    public const ATTENDANCE_READ_CURRENT_STATE = 'attendance:read_current_state';

    public const ATTENDANCE_READ_RESULT = 'attendance:read_result';

    public const IDENTITY_RESOLVE = 'identity:resolve';

    public const DEVICE_HEARTBEAT = 'device:heartbeat';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::ATTENDANCE_CLOCK, self::ATTENDANCE_READ_CURRENT_STATE, self::ATTENDANCE_READ_RESULT,
            self::IDENTITY_RESOLVE, self::DEVICE_HEARTBEAT,
        ];
    }
}
