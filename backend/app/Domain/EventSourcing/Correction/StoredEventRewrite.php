<?php

namespace App\Domain\EventSourcing\Correction;

use InvalidArgumentException;

/**
 * stored_events の既存行1件に対する直接修正(.claude/skills/data-correction ステップ2)。
 *
 * - REWRITE: event_properties を指定の内容へ書き換える(版・event_classは変えない)。
 * - DELETE: 行を削除する(誤って記録されたイベント)。削除後も同じ集約の版が連続していることを検証する。
 *
 * 過去の時点へのイベントの挿入は表現できない(data-correction: 挿入は直接修正として扱わない)。
 */
final class StoredEventRewrite
{
    public const REWRITE = 'rewrite';

    public const DELETE = 'delete';

    /**
     * @param  array<string, mixed>|null  $eventProperties  REWRITEの書き換え後のpayload(DELETEでは null)
     */
    public function __construct(
        public readonly int $storedEventId,
        public readonly string $operation,
        public readonly ?array $eventProperties = null,
    ) {
        if (! in_array($operation, [self::REWRITE, self::DELETE], true)) {
            throw new InvalidArgumentException("未対応の操作です: {$operation}");
        }
        if ($operation === self::REWRITE && $eventProperties === null) {
            throw new InvalidArgumentException('REWRITEには書き換え後のevent_propertiesが必要です。');
        }
        if ($operation === self::DELETE && $eventProperties !== null) {
            throw new InvalidArgumentException('DELETEには書き換え後のevent_propertiesを指定できません。');
        }
    }

    /** @param  array<string, mixed>  $eventProperties */
    public static function rewrite(int $storedEventId, array $eventProperties): self
    {
        return new self($storedEventId, self::REWRITE, $eventProperties);
    }

    public static function delete(int $storedEventId): self
    {
        return new self($storedEventId, self::DELETE);
    }
}
