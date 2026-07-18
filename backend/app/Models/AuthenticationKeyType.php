<?php

namespace App\Models;

/**
 * authentication_keys.key_type。NFCカード専用にせず、生体認証端末の外部ID・QR・FIDO等も
 * 同じテーブルで扱う。docs/24-usecases-authentication-keys.md参照。
 */
final class AuthenticationKeyType
{
    public const NFC_UID = 'nfc_uid';

    public const EMPLOYEE_CARD_ID = 'employee_card_id';

    public const QR_CODE = 'qr_code';

    public const BARCODE = 'barcode';

    public const FINGERPRINT_EXTERNAL_ID = 'fingerprint_external_id';

    public const FACE_RECOGNITION_EXTERNAL_ID = 'face_recognition_external_id';

    public const FIDO_CREDENTIAL = 'fido_credential';

    public const BLUETOOTH_DEVICE_ID = 'bluetooth_device_id';

    public const EXTERNAL_SYSTEM_USER_ID = 'external_system_user_id';

    public const CUSTOM = 'custom';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::NFC_UID, self::EMPLOYEE_CARD_ID, self::QR_CODE, self::BARCODE,
            self::FINGERPRINT_EXTERNAL_ID, self::FACE_RECOGNITION_EXTERNAL_ID,
            self::FIDO_CREDENTIAL, self::BLUETOOTH_DEVICE_ID, self::EXTERNAL_SYSTEM_USER_ID,
            self::CUSTOM,
        ];
    }
}
