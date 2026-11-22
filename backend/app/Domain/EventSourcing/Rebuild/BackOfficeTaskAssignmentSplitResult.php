<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * BackOfficeTaskAssignmentSplitter の結果。violations があるときは rows は空(何も書かない)。
 *
 * @phpstan-type Row array{id: int, aggregate_uuid: ?string, aggregate_version: ?int, event_version: int, event_class: string, event_properties: array<string, mixed>, meta_data: array<string, mixed>, created_at: string}
 */
final readonly class BackOfficeTaskAssignmentSplitResult
{
    /**
     * @param  list<Row>  $rows  作り直し後の行(id順)。violations があれば空
     * @param  int  $targetCount  変換した旧形式の assigned の件数
     * @param  int  $insertedStatusChanged  挿入した status_changed の件数
     * @param  array<string, int>  $previousStatusCounts  旧形式の assigned の直前の status 別の件数
     * @param  array<string, list<string>>  $terminalTransitions  直前が completed/cancelled だった assigned の集約ID(直前の status => 集約IDの一覧)
     * @param  int  $skippedConverted  変換済みでそのまま写した assigned の件数
     * @param  list<string>  $violations  変換できない並びの説明(空なら問題なし)
     * @param  array<int, int>  $idMap  旧id => 新id(元の行すべて。変換対象があったときだけ)
     * @param  array<int, int>  $versionMap  旧id => 新しい aggregate_version(版を持つ元の行)
     */
    public function __construct(
        public array $rows,
        public int $targetCount,
        public int $insertedStatusChanged,
        public array $previousStatusCounts,
        public array $terminalTransitions,
        public int $skippedConverted,
        public array $violations,
        public array $idMap = [],
        public array $versionMap = [],
    ) {}
}
