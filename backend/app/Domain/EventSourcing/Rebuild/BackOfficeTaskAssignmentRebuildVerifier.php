<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * バックオフィスタスクの担当者割当イベントの作り直し(BackOfficeTaskAssignmentSplitter)の検証。作り直し後の表($table)と
 * 元の表(または行の配列。$source)を読む(読み取りのみ)。
 *
 * (1) EventStoreRebuildWriter::verify(id・版の連番)
 * (2) previousAssignedUserId キーの無い旧形式の backoffice_task.assigned が0件
 * (3) backoffice_task の集約以外の行が、id と meta の stored-event-id を除いて元の表と一致する(件数・順序も)
 * (4) EventRestorabilityChecker が通る
 */
final class BackOfficeTaskAssignmentRebuildVerifier
{
    private const CHUNK = 500;

    private const MAX_REPORTED = 20;

    public function __construct(private readonly EventStoreRebuildWriter $writer = new EventStoreRebuildWriter) {}

    /**
     * @param  string|list<array<string, mixed>>  $source  元の表の名前、または元の行の配列(EventStoreRebuildWriter::readRowArrays の形)
     * @return list<string> 違反の説明(空なら問題なし)
     */
    public function verify(string $table = EventStoreRebuildWriter::TABLE, string|array $source = 'stored_events'): array
    {
        if (! Schema::hasTable($table)) {
            return ["{$table} が存在しない"];
        }

        $problems = [];
        $reported = ['2' => 0, '3' => 0];
        $report = function (string $key, string $message) use (&$problems, &$reported): void {
            if ($reported[$key] < self::MAX_REPORTED) {
                $problems[] = "({$key}) {$message}";
            } elseif ($reported[$key] === self::MAX_REPORTED) {
                $problems[] = "({$key}) 以降は省略";
            }
            $reported[$key]++;
        };

        // (1)
        foreach ($this->writer->verify($table) as $problem) {
            $problems[] = "(1) {$problem}";
        }

        // (3) の元の行: backoffice_task の集約を除いた行の指紋(id順)。
        $expected = [];
        foreach ($this->nonTaskRows($source) as $row) {
            $expected[] = [(int) $row->id, (string) $row->event_class, $this->fingerprint($row)];
        }

        $actual = [];
        foreach ($this->nonTaskRows($table) as $row) {
            $actual[] = [(int) $row->id, (string) $row->event_class, $this->fingerprint($row)];
        }

        // (2)
        DB::table($table)->where('event_class', BackOfficeTaskAssignmentSplitter::ASSIGNED)->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use ($report): void {
            foreach ($chunk as $row) {
                $payload = json_decode((string) $row->event_properties, true) ?: [];
                if (! array_key_exists('previousAssignedUserId', $payload)) {
                    $report('2', "旧形式の assigned が残っている: id={$row->id}");
                }
            }
        });

        // (3)
        if (count($expected) !== count($actual)) {
            $report('3', 'backoffice_task 以外の行数が元の表と違う: 元 '.count($expected).' 件、作り直し後 '.count($actual).' 件');
        }
        foreach ($expected as $i => [$id, $class, $hash]) {
            if (! isset($actual[$i])) {
                $report('3', "元の表の行が無くなっている: 元の id={$id} {$class}");
            } elseif ($actual[$i][2] !== $hash) {
                $report('3', "元の表と内容が違う: 元の id={$id} {$class}、作り直し後の id={$actual[$i][0]}");
            }
        }

        // (4)
        foreach ((new EventRestorabilityChecker)->checkTable($table) as $class => $failure) {
            $problems[] = "(4) 現行のイベントクラスへ復元できない: {$class} {$failure['count']} 件(先頭 id={$failure['first_id']}) {$failure['message']}";
        }

        return $problems;
    }

    /**
     * backoffice_task.* の行を持つ集約を除いた行を id順に返す。
     *
     * @param  string|list<array<string, mixed>>  $table  表の名前、または行の配列
     * @return list<object>
     */
    private function nonTaskRows(string|array $table): array
    {
        if (is_array($table)) {
            return $this->nonTaskRowsOfArray($table);
        }

        $taskAggregates = array_flip(array_map('strval', DB::table($table)
            ->where('event_class', 'like', BackOfficeTaskAssignmentSplitter::PREFIX.'%')
            ->whereNotNull('aggregate_uuid')->distinct()->pluck('aggregate_uuid')->all()));

        $rows = [];
        DB::table($table)->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$rows, $taskAggregates): void {
            foreach ($chunk as $row) {
                if ($row->aggregate_uuid !== null && isset($taskAggregates[(string) $row->aggregate_uuid])) {
                    continue;
                }
                $rows[] = $row;
            }
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<object>
     */
    private function nonTaskRowsOfArray(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);

        $taskAggregates = [];
        foreach ($rows as $row) {
            if ($row['aggregate_uuid'] !== null && str_starts_with((string) $row['event_class'], BackOfficeTaskAssignmentSplitter::PREFIX)) {
                $taskAggregates[(string) $row['aggregate_uuid']] = true;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            if ($row['aggregate_uuid'] !== null && isset($taskAggregates[(string) $row['aggregate_uuid']])) {
                continue;
            }
            $result[] = (object) [
                'id' => $row['id'],
                'aggregate_uuid' => $row['aggregate_uuid'],
                'aggregate_version' => $row['aggregate_version'],
                'event_class' => $row['event_class'],
                'event_version' => $row['event_version'],
                'event_properties' => json_encode($row['event_properties'], JSON_THROW_ON_ERROR),
                'meta_data' => json_encode($row['meta_data'], JSON_THROW_ON_ERROR),
                'created_at' => $row['created_at'],
            ];
        }

        return $result;
    }

    /** id・meta の stored-event-id を除いた、行の正規化したハッシュ(JSONの書式の差を吸収する)。 */
    private function fingerprint(object $row): string
    {
        $meta = json_decode((string) ($row->meta_data ?? '[]'), true) ?: [];
        unset($meta['stored-event-id']);

        return hash('sha256', json_encode([
            $row->aggregate_uuid,
            $row->aggregate_version !== null ? (int) $row->aggregate_version : null,
            (string) $row->event_class,
            (int) ($row->event_version ?? 1),
            $this->canonical(json_decode((string) $row->event_properties, true)),
            $this->canonical($meta),
            (string) $row->created_at,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $value = array_map(fn ($v) => $this->canonical($v), $value);
            if (! $isList) {
                ksort($value);
            }
        }

        return $value;
    }
}
