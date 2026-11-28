<?php

namespace App\Domain\EventSourcing;

use App\Domain\UserManagement\Support\UserManagementStreamId;

/**
 * 休暇の手動付与の付与ID。画面が送る`request_id`(UUID)と利用者のIDから決める(UUIDv5)。
 * 同じ`request_id`の再送は同じ付与IDになり、付与済みなら何もせず成功とする(二重付与にならない)。
 */
final class ManualGrantId
{
    /**
     * @param  string  $kind  paid_leave / special_leave / compensatory_leave
     */
    public static function for(string $kind, string $requestId, string $userId): string
    {
        return UserManagementStreamId::for("manual_{$kind}_grant", "{$requestId}:{$userId}");
    }
}
