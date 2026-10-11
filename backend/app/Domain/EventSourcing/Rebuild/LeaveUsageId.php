<?php

namespace App\Domain\EventSourcing\Rebuild;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * 消化記録(usageId)の決定的な採番(論点18 assets/event-rebuild-mapping.md 2.1)。
 *
 * usageId = UUIDv5(NAMESPACE, "{種類}:{申請ID}:{再提出の回数}")。作り直しの変換と、以後の新規採番
 * (DesignatePaidLeaveUsageHandler等)で同じ関数を使う。名前空間の値は変更しない。
 */
final class LeaveUsageId
{
    public const NAMESPACE = '3f9a6c1e-5b2d-4e8f-a7c4-9d0e1b2f3a58';

    public static function for(string $leaveKind, string $requestId, int $resubmitCount): string
    {
        if ($leaveKind === '' || $requestId === '') {
            throw new InvalidArgumentException('種類と申請IDは空にできません。');
        }
        if ($resubmitCount < 0) {
            throw new InvalidArgumentException('再提出の回数は0以上です。');
        }

        return Uuid::uuid5(self::NAMESPACE, "{$leaveKind}:{$requestId}:{$resubmitCount}")->toString();
    }
}
