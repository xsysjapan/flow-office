<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 端末の設置場所・自動反映する勤務形態区分などの設定を変更する(docs/23-usecases-devices.md
 * 「端末管理画面(UI)」)。役割(roles)・スコープ(scopes)・稼働状態(status)は別のCommandで
 * 扱うため、ここでは含めない。
 */
class UpdateDeviceSettings implements Command
{
    public function __construct(
        public readonly string $deviceId,
        public readonly string $name,
        public readonly ?string $siteId,
        public readonly ?string $locationName,
        public readonly ?string $defaultWorkLocationType,
        public readonly ?string $timezone,
        public readonly ?array $allowedPunchTypes,
        public readonly bool $allowOffline,
        public readonly bool $requireLocation,
        public readonly bool $autoDetectPunchType,
        public readonly int $updatedByUserId,
    ) {}
}
