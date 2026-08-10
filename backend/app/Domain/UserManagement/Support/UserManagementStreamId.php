<?php

namespace App\Domain\UserManagement\Support;

use Ramsey\Uuid\Uuid;

final class UserManagementStreamId
{
    public static function for(string $aggregateType, string|int $businessId): string
    {
        // StoredEventの既存aggregate_uuidを変えないため、旧プレフィックスを互換識別子として維持する。
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "access-control:{$aggregateType}:{$businessId}")->toString();
    }
}
