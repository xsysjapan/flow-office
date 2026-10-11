<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 作り直し後の stored_events の1行(まだ id・版は振っていない)。
 *
 * - anchorId: 並べる位置を決める元の stored_events.id(その事実が起きた旧イベントの位置)。
 * - subOrder: 同じ anchorId の中の順序(0が元の行そのもの、1以降はその直後に補う行)。
 * - rebuiltFrom: 元の行から分割・補完して作った行の、元の stored_events.id(補った行にだけ付ける)。
 * - sourceVersion: 元の行の aggregate_version(休暇以外の版が変わっていないことの確認に使う)。
 */
final readonly class RebuiltEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $eventClass,
        public ?string $aggregateUuid,
        public array $payload,
        public array $meta,
        public string $createdAt,
        public int $anchorId,
        public int $subOrder,
        public ?int $rebuiltFrom,
        public ?int $sourceVersion,
    ) {}

    /** 元の行をそのまま写す(休暇以外のイベント・1対1の変換の行)。 */
    public static function passThrough(StoredEventRow $row): self
    {
        return new self(
            eventClass: $row->eventClass,
            aggregateUuid: $row->aggregateUuid,
            payload: $row->payload,
            meta: $row->meta,
            createdAt: $row->createdAt,
            anchorId: $row->id,
            subOrder: 0,
            rebuiltFrom: null,
            sourceVersion: $row->aggregateVersion,
        );
    }

    /**
     * 元の行(anchor)の位置に、変換で作った行を置く。created_at と meta は anchor から引き継ぐ。
     *
     * @param  array<string, mixed>  $payload
     */
    public static function derived(
        StoredEventRow $anchor,
        int $subOrder,
        string $eventClass,
        string $aggregateUuid,
        array $payload,
        ?int $rebuiltFrom = null,
    ): self {
        return new self(
            eventClass: $eventClass,
            aggregateUuid: $aggregateUuid,
            payload: $payload,
            meta: $anchor->meta,
            createdAt: $anchor->createdAt,
            anchorId: $anchor->id,
            subOrder: $subOrder,
            rebuiltFrom: $rebuiltFrom,
            sourceVersion: null,
        );
    }
}
