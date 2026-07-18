<?php

namespace App\Models;

/**
 * attendance_days.work_location_type。docs/07-usecases-attendance.md「勤務形態区分」参照。
 * 法令で変動する値ではないためマスタ化せず、PunchTypeと同様に定数クラスで列挙する。
 * 時間計算には一切影響しない分類情報。
 */
final class WorkLocationType
{
    public const OFFICE = 'office';

    public const REMOTE = 'remote';

    public const CLIENT_SITE = 'client_site';

    public const BUSINESS_TRIP = 'business_trip';

    public const DIRECT_TO_SITE = 'direct_to_site';

    public const DIRECT_FROM_SITE = 'direct_from_site';

    public const OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::OFFICE, self::REMOTE, self::CLIENT_SITE, self::BUSINESS_TRIP,
            self::DIRECT_TO_SITE, self::DIRECT_FROM_SITE, self::OTHER,
        ];
    }
}
