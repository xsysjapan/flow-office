<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\StoredEventRow;

/**
 * 導出イベントを置く位置。RebuiltEvent の anchorId・subOrder(元のstored_events.idと同じ位置の中の順序)で表す。
 *
 * - before=true: 元の行(anchorId)より前。subOrder は負の値を取る。
 * - before=false: 元の行(anchorId)より後(anchorId=0 は列の先頭)。subOrder は正の値を取る。
 * - minSub: この位置の直後に置くために、次に割り当てる subOrder の下限(この位置の subOrder)。
 * - createdAt: この位置に置くイベントの発生日時の既定値(置き場所の前のイベントの値、または行の created_at)。
 * - meta: anchor の行の meta_data(anchorが無ければ空)。
 */
final readonly class Slot
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $anchorId,
        public bool $before,
        public int $minSub,
        public string $createdAt,
        public array $meta = [],
    ) {}

    public static function ofRow(StoredEventRow $row): self
    {
        return new self($row->id, false, 0, $row->occurredAt(), $row->meta);
    }

    /** 並び順の比較(anchorId、subOrder の順)。 */
    public function compareTo(self $other): int
    {
        return [$this->anchorId, $this->minSub] <=> [$other->anchorId, $other->minSub];
    }
}
