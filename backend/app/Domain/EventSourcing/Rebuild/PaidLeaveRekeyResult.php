<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * PaidLeaveStreamRekeyer の結果。applied=false のときは事前条件に違反しており、rows は入力のまま(変換していない)。
 *
 * @phpstan-type Row array{id: int, aggregate_uuid: ?string, aggregate_version: ?int, event_version: int, event_class: string, event_properties: array<string, mixed>, meta_data: array<string, mixed>, created_at: string}
 */
final readonly class PaidLeaveRekeyResult
{
    /**
     * @param  list<Row>  $rows  変換後の行(id順)
     * @param  bool  $applied  事前条件を満たして変換した
     * @param  int  $rekeyedRows  新しいストリームIDへ付け替えた有給口座の行数
     * @param  array<string, int>  $removedByClass  取り除いた付与予定の行数(event_class別)
     * @param  array<string, string>  $mapping  旧ID(社員ID) => 新ID(有給口座のストリームID)
     * @param  array<string, int>  $violations  事前条件の違反(説明 => 件数。空なら満たしている)
     * @param  int  $renumberedStreams  版を振り直したストリーム数
     * @param  int  $renumberedRows  版が実際に変わった行数
     */
    public function __construct(
        public array $rows,
        public bool $applied,
        public int $rekeyedRows,
        public array $removedByClass,
        public array $mapping,
        public array $violations,
        public int $renumberedStreams,
        public int $renumberedRows,
    ) {}

    public function removedRows(): int
    {
        return array_sum($this->removedByClass);
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'applied' => $this->applied,
            'rekeyed_rows' => $this->rekeyedRows,
            'removed_by_class' => $this->removedByClass,
            'removed_rows' => $this->removedRows(),
            'mapping_count' => count($this->mapping),
            'violations' => $this->violations,
            'renumbered_streams' => $this->renumberedStreams,
            'renumbered_rows' => $this->renumberedRows,
        ];
    }
}
