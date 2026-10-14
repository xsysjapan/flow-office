<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use RuntimeException;

/**
 * 照合の共有状態(論点19 決定1)。元の stored_events の行の索引、導出イベントを置く位置の割り当て、導出イベントの蓄積、
 * 報告(テーブルごとの件数・イベントがあるのに最終状態が違ったキー・扱えない並び)を持つ。
 *
 * 置く位置は Slot で表す。同じ位置(anchorId・前後)に置く導出イベントは、割り当てた順に並ぶ(subOrder を増やす)ため、
 * テーブルの依存順(SeededDataReconciler)で呼べば、依存先の導出イベントが依存元より前になる。
 */
final class ReconcileContext
{
    /** 「元の行より前」に置く導出イベントの subOrder の起点(負の値。元の行の 0 より前)。 */
    private const BEFORE_BASE = -1000000;

    /** @var list<StoredEventRow> id順 */
    private array $rows;

    /** @var array<string, list<StoredEventRow>> 集約UUID => 行(id順) */
    private array $byAggregate = [];

    /** @var list<int> id順の昇順 */
    private array $ids = [];

    /** @var array<int, StoredEventRow> */
    private array $rowById = [];

    /** @var array<int, int> anchorId => 「前」に最後に割り当てた subOrder */
    private array $negative = [];

    /** @var array<int, int> anchorId => 「後」に最後に割り当てた subOrder */
    private array $positive = [];

    /** @var array<string, Slot> */
    private array $refs = [];

    /** @var list<RebuiltEvent> */
    public array $events = [];

    /** @var array<string, array<string, int>> */
    public array $derivedByTable = [];

    /** @var array<string, list<string>> */
    public array $mismatches = [];

    /** @var list<array{key: string, reason: string}> */
    public array $issues = [];

    /**
     * @param  list<StoredEventRow>  $rows
     */
    public function __construct(array $rows, public readonly SeededDataCurrent $current)
    {
        usort($rows, fn (StoredEventRow $a, StoredEventRow $b) => $a->id <=> $b->id);
        $this->rows = $rows;
        foreach ($rows as $row) {
            $this->ids[] = $row->id;
            $this->rowById[$row->id] = $row;
            if ($row->aggregateUuid !== null) {
                $this->byAggregate[$row->aggregateUuid][] = $row;
            }
        }
    }

    /** @return list<StoredEventRow> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<StoredEventRow> */
    public function rowsOf(string $aggregateUuid): array
    {
        return $this->byAggregate[$aggregateUuid] ?? [];
    }

    /**
     * 集約の行のうち event_class が一致するもの(id順)。
     *
     * @param  list<string>  $classes
     * @return list<StoredEventRow>
     */
    public function rowsOfClasses(string $aggregateUuid, array $classes): array
    {
        return array_values(array_filter($this->rowsOf($aggregateUuid), fn (StoredEventRow $r) => in_array($r->eventClass, $classes, true)));
    }

    /** 全体で $id より前の最後の行(無ければ null)。 */
    private function previousRow(int $id): ?StoredEventRow
    {
        $low = 0;
        $high = count($this->ids) - 1;
        $found = null;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->ids[$mid] < $id) {
                $found = $this->ids[$mid];
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $found === null ? null : $this->rowById[$found];
    }

    // ---- 位置 ----

    /**
     * 列の先頭(どのイベントよりも前)。発生日時は行の created_at、無ければ最初のイベントの値。
     */
    public function head(?string $rowCreatedAt): Slot
    {
        $createdAt = $rowCreatedAt ?? ($this->rows[0] ?? null)?->occurredAt();
        if ($createdAt === null) {
            throw new UnsupportedEventSequence('(列の先頭)', '行の created_at もイベントも無く、導出イベントの発生日時を決められない');
        }

        return new Slot(0, false, 0, $createdAt);
    }

    /**
     * 集約の最初のイベントの直前。集約にイベントが無ければ null。発生日時は置き場所の前のイベントの値
     * (前のイベントが無ければ行の created_at、それも無ければ最初のイベントの値)。
     */
    public function beforeFirst(string $aggregateUuid, ?string $rowCreatedAt = null): ?Slot
    {
        $first = $this->rowsOf($aggregateUuid)[0] ?? null;
        if ($first === null) {
            return null;
        }
        $previous = $this->previousRow($first->id);

        return new Slot($first->id, true, self::BEFORE_BASE - 1, $previous?->occurredAt() ?? $rowCreatedAt ?? $first->occurredAt(), $first->meta);
    }

    /** 集約の最後のイベントの直後。集約にイベントが無ければ null。発生日時はそのイベントの値。 */
    public function afterLast(string $aggregateUuid): ?Slot
    {
        $rows = $this->rowsOf($aggregateUuid);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return $last === null ? null : Slot::ofRow($last);
    }

    /** 既存の位置(依存先の作成イベント等)の直後。発生日時は行の created_at、無ければその位置の値。 */
    public function afterRef(Slot $ref, ?string $rowCreatedAt = null): Slot
    {
        return new Slot($ref->anchorId, $ref->before, $ref->minSub, $rowCreatedAt ?? $ref->createdAt, $ref->meta);
    }

    /**
     * $slot が $dep より前に来てしまうとき、$dep の直後に置き直す(依存先の作成イベントより前に依存元を置かない)。
     */
    public function notBefore(Slot $slot, ?Slot $dep, ?string $rowCreatedAt = null): Slot
    {
        if ($dep !== null && $dep->compareTo($slot) > 0) {
            return $this->afterRef($dep, $rowCreatedAt);
        }

        return $slot;
    }

    /** 位置の一覧のうち最も後ろのもの(null は無視。全て null なら null)。 */
    public function latest(?Slot ...$slots): ?Slot
    {
        $latest = null;
        foreach ($slots as $slot) {
            if ($slot !== null && ($latest === null || $slot->compareTo($latest) > 0)) {
                $latest = $slot;
            }
        }

        return $latest;
    }

    // ---- 作成イベントの位置の登録 ----

    public function registerRef(string $key, Slot $slot, bool $overwrite = false): void
    {
        if ($overwrite || ! isset($this->refs[$key])) {
            $this->refs[$key] = $slot;
        }
    }

    public function ref(string $key): ?Slot
    {
        return $this->refs[$key] ?? null;
    }

    // ---- 導出イベントの出力 ----

    /**
     * 導出イベントを $slot の位置に置く。戻り値は、置いたイベントの位置(直後に続けて置くのに使う)。
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $table, Slot $slot, string $eventClass, string $aggregateUuid, array $payload, ?string $createdAt = null): Slot
    {
        if ($slot->before) {
            $subOrder = max($this->negative[$slot->anchorId] ?? (self::BEFORE_BASE - 1), $slot->minSub) + 1;
            if ($subOrder >= 0) {
                throw new RuntimeException('「元の行より前」に置く導出イベントが多すぎる(anchorId='.$slot->anchorId.')');
            }
            $this->negative[$slot->anchorId] = $subOrder;
        } else {
            $subOrder = max($this->positive[$slot->anchorId] ?? 0, $slot->minSub) + 1;
            $this->positive[$slot->anchorId] = $subOrder;
        }

        $createdAt ??= $slot->createdAt;
        $this->events[] = new RebuiltEvent(
            eventClass: $eventClass,
            aggregateUuid: $aggregateUuid,
            payload: $payload,
            meta: $slot->meta,
            createdAt: $createdAt,
            anchorId: $slot->anchorId,
            subOrder: $subOrder,
            rebuiltFrom: null,
            sourceVersion: null,
        );
        $this->derivedByTable[$table][$eventClass] = ($this->derivedByTable[$table][$eventClass] ?? 0) + 1;

        return new Slot($slot->anchorId, $slot->before, $subOrder, $createdAt, $slot->meta);
    }

    // ---- 報告 ----

    /** イベントがあるのに最終状態が違ったキーを報告に載せる。 */
    public function mismatch(string $table, string $key, string $reason): void
    {
        $this->mismatches[$table][] = "{$key}: {$reason}";
    }

    public function issue(string $key, string $reason): void
    {
        $this->issues[] = ['key' => $key, 'reason' => $reason];
    }

    /**
     * 1キーぶんの処理を実行し、扱えない並びはキーごとに issues へ載せて続ける。
     */
    public function guard(string $key, callable $work): void
    {
        try {
            $work();
        } catch (UnsupportedEventSequence $e) {
            $this->issue($e->key === '' ? $key : $e->key, $e->getMessage());
        }
    }
}
