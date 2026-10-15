<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 作り直しの結果。ok=false のときは rows を持たず、unsupported に扱えなかった並びを載せる。
 *
 * @phpstan-type Row array{id: int, aggregate_uuid: ?string, aggregate_version: ?int, event_version: int, event_class: string, event_properties: array<string, mixed>, meta_data: array<string, mixed>, created_at: string}
 */
final readonly class RebuildResult
{
    /**
     * @param  list<Row>  $rows  stored_events と同じ構造の作り直し後の行(id・版を振り直し済み)
     * @param  list<array{key: string, reason: string}>  $unsupported  扱えなかった並び
     * @param  array<string, mixed>  $report  件数・申請の状態・cutoverの突き合わせ結果など
     */
    private function __construct(
        public bool $ok,
        public array $rows,
        public array $unsupported,
        public array $report,
    ) {}

    /**
     * @param  list<Row>  $rows
     * @param  array<string, mixed>  $report
     */
    public static function succeeded(array $rows, array $report): self
    {
        return new self(ok: true, rows: $rows, unsupported: [], report: $report);
    }

    /**
     * @param  list<array{key: string, reason: string}>  $unsupported
     * @param  array<string, mixed>  $report
     */
    public static function failed(array $unsupported, array $report): self
    {
        return new self(ok: false, rows: [], unsupported: $unsupported, report: $report);
    }
}
