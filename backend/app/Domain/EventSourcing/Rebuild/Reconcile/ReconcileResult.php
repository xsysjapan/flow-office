<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

/**
 * 照合による補完(論点19 決定1)の結果。
 *
 * @phpstan-type Issue array{key: string, reason: string}
 */
final readonly class ReconcileResult
{
    /**
     * @param  list<\App\Domain\EventSourcing\Rebuild\RebuiltEvent>  $events  導出イベントと、集約を付け替えた既存イベント
     * @param  list<int>  $consumedIds  付け替えで置き換える(元の位置の行は削除する)既存の行のid
     * @param  list<array{key: string, reason: string}>  $issues  扱えない並び
     * @param  array<string, array<string, int>>  $derivedByTable  テーブル => event_class => 導出した件数
     * @param  array<string, list<array{key: string, reason: string, derivedBy: list<string>}>>  $resolved  テーブル => 導出で解消した差(導出した event_class つき)
     * @param  array<string, list<string>>  $unresolved  テーブル => 解消していない差(「key: reason」)
     * @param  list<array{id: int, from: string, to: string}>  $remapped  集約UUIDを付け替えた days_updated
     */
    public function __construct(
        public array $events,
        public array $consumedIds,
        public array $issues,
        public array $derivedByTable,
        public array $resolved,
        public array $unresolved,
        public array $remapped,
    ) {}

    public static function empty(): self
    {
        return new self([], [], [], [], [], [], []);
    }
}
