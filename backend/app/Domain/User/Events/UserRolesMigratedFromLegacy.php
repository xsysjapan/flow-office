<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Seeder等で直接作成されたrole_userの移行時点スナップショットを記録する合成イベント。
 *
 * @param  array<int, string>  $roleCodes
 */
class UserRolesMigratedFromLegacy extends ShouldBeStored
{
    public function __construct(
        public readonly array $roleCodes,
    ) {}
}
