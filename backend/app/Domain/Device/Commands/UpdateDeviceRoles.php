<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 共有端末の役割(device_roles)を、登録時に選べる役割と同じ選択肢
 * (`attendance_reader`/`authentication_device`/`access_control`)で入れ替える。
 */
class UpdateDeviceRoles implements Command
{
    /**
     * @param  array<int, string>  $roleTypes
     */
    public function __construct(
        public readonly string $deviceId,
        public readonly array $roleTypes,
        public readonly string $updatedByUserId,
    ) {}
}
