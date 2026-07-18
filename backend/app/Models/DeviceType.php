<?php

namespace App\Models;

/**
 * devices.device_type。docs/23-usecases-devices.md参照。
 */
final class DeviceType
{
    public const ANDROID = 'android';

    public const IOS = 'ios';

    public const WEB_BROWSER = 'web_browser';

    public const WINDOWS = 'windows';

    public const MACOS = 'macos';

    public const LINUX = 'linux';

    public const NFC_READER = 'nfc_reader';

    public const FINGERPRINT_READER = 'fingerprint_reader';

    public const FACE_RECOGNITION_DEVICE = 'face_recognition_device';

    public const ACCESS_CONTROL_DEVICE = 'access_control_device';

    public const IOT_DEVICE = 'iot_device';

    public const EXTERNAL_SYSTEM = 'external_system';

    public const OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::ANDROID, self::IOS, self::WEB_BROWSER, self::WINDOWS, self::MACOS, self::LINUX,
            self::NFC_READER, self::FINGERPRINT_READER, self::FACE_RECOGNITION_DEVICE,
            self::ACCESS_CONTROL_DEVICE, self::IOT_DEVICE, self::EXTERNAL_SYSTEM, self::OTHER,
        ];
    }
}
