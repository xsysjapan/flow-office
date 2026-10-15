<?php

namespace App\Domain\EventSourcing\Rebuild;

use Carbon\Carbon;

/**
 * ReadModelの2つのDB(現在=作り直し後に再生した側、比較先=作り直し前)の行単位の差(論点19 決定6のリハーサル用)。
 *
 * 読み取りのみ。正規化・除外列は ReadModelFingerprinter と同じ。
 * 行の同一性は、自動採番でない1列の主キーがあればそのキー(「内容が違う行」を出す)。無ければ(自動採番の整数主キー・複合主キー・
 * 主キー無し)除外列以外の全列の組で、「変化」は出さず両側にだけある行として数える。
 * 両側にある列だけで比べ、片側にしかない列は columns_only_current / columns_only_other に出す。
 */
final class ReadModelDiffer
{
    public const MAX_EXAMPLES = 5;

    private readonly ReadModelFingerprinter $current;

    private readonly ReadModelFingerprinter $other;

    public function __construct(?string $currentConnection, string $otherConnection)
    {
        $this->current = new ReadModelFingerprinter($currentConnection);
        $this->other = new ReadModelFingerprinter($otherConnection);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, array<string, mixed>> テーブル名 => 差の要約
     */
    public function diff(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $this->diffTable($table);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function diffTable(string $table): array
    {
        $current = $this->current->normalizedRows($table);
        $other = $this->other->normalizedRows($table);

        if ($current === null) {
            return ['status' => 'missing_current', 'count_other' => $other === null ? null : count($other['rows']), 'count_current' => null];
        }
        if ($other === null) {
            return ['status' => 'new', 'count_other' => null, 'count_current' => count($current['rows'])];
        }

        $common = array_values(array_intersect(array_keys($current['kinds']), array_keys($other['kinds'])));
        $key = ($current['key'] !== null && $current['key'] === $other['key'] && in_array($current['key'], $common, true)) ? $current['key'] : null;
        $kinds = array_intersect_key($current['kinds'], array_flip($common));

        $summary = [
            'status' => 'compared',
            'key' => $key,
            'count_other' => count($other['rows']),
            'count_current' => count($current['rows']),
            'only_other' => 0,
            'only_current' => 0,
            'changed' => 0,
            'changed_columns' => [],
            'changed_examples' => [],
            'datetime_diff_seconds' => [],
            'only_other_examples' => [],
            'only_current_examples' => [],
            'columns_only_current' => array_values(array_diff(array_keys($current['kinds']), $common)),
            'columns_only_other' => array_values(array_diff(array_keys($other['kinds']), $common)),
        ];

        $currentRows = $this->project($current['rows'], $common);
        $otherRows = $this->project($other['rows'], $common);

        if ($key !== null) {
            $this->compareByKey($summary, $key, $kinds, $currentRows, $otherRows);
        } else {
            $this->compareByWholeRow($summary, $currentRows, $otherRows);
        }

        ksort($summary['changed_columns']);
        foreach ($summary['datetime_diff_seconds'] as $column => $counts) {
            ksort($counts);
            $summary['datetime_diff_seconds'][$column] = $counts;
        }
        ksort($summary['datetime_diff_seconds']);

        return $summary;
    }

    /**
     * 人が読める表の行(テーブル, 件数(比較先), 件数(現在), 比較先のみ, 現在のみ, 変化, 備考)。
     *
     * @param  array<string, array<string, mixed>>  $diff
     * @return list<array<int, string|int>>
     */
    public static function summaryRows(array $diff): array
    {
        $rows = [];
        foreach ($diff as $table => $d) {
            if ($d['status'] === 'new') {
                $rows[] = [$table, '-', $d['count_current'], '-', '-', '-', '新設'];

                continue;
            }
            if ($d['status'] === 'missing_current') {
                $rows[] = [$table, $d['count_other'] ?? '-', '-', '-', '-', '-', '現在のDBにテーブルなし'];

                continue;
            }
            $rows[] = [$table, $d['count_other'], $d['count_current'], $d['only_other'], $d['only_current'],
                $d['key'] === null ? '(キーなし)' : $d['changed'], $d['key'] === null ? '全列の組' : 'キー: '.$d['key']];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, string>  $kinds
     * @param  list<array<string, string>>  $currentRows
     * @param  list<array<string, string>>  $otherRows
     */
    private function compareByKey(array &$summary, string $key, array $kinds, array $currentRows, array $otherRows): void
    {
        $currentByKey = [];
        foreach ($currentRows as $row) {
            $currentByKey[$row[$key]] = $row;
        }
        $otherByKey = [];
        foreach ($otherRows as $row) {
            $otherByKey[$row[$key]] = $row;
        }
        ksort($currentByKey, SORT_STRING);
        ksort($otherByKey, SORT_STRING);

        foreach ($otherByKey as $k => $otherRow) {
            if (! array_key_exists($k, $currentByKey)) {
                $summary['only_other']++;
                $this->addExample($summary['only_other_examples'], ['key' => (string) $k, 'row' => $otherRow]);

                continue;
            }
            $currentRow = $currentByKey[$k];
            $rowChanged = false;
            foreach ($kinds as $column => $kind) {
                if ($otherRow[$column] === $currentRow[$column]) {
                    continue;
                }
                $rowChanged = true;
                $summary['changed_columns'][$column] = ($summary['changed_columns'][$column] ?? 0) + 1;
                $example = ['key' => (string) $k, 'column' => $column, 'other' => $otherRow[$column], 'current' => $currentRow[$column]];
                $seconds = $kind === 'datetime' ? $this->secondsDiff($otherRow[$column], $currentRow[$column]) : null;
                if ($seconds !== null) {
                    $example['diff_seconds'] = $seconds;
                    $label = ($seconds >= 0 ? '+' : '').$seconds;
                    $summary['datetime_diff_seconds'][$column][$label] = ($summary['datetime_diff_seconds'][$column][$label] ?? 0) + 1;
                }
                $this->addExample($summary['changed_examples'], $example);
            }
            if ($rowChanged) {
                $summary['changed']++;
            }
        }
        foreach ($currentByKey as $k => $currentRow) {
            if (! array_key_exists($k, $otherByKey)) {
                $summary['only_current']++;
                $this->addExample($summary['only_current_examples'], ['key' => (string) $k, 'row' => $currentRow]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, string>>  $currentRows
     * @param  list<array<string, string>>  $otherRows
     */
    private function compareByWholeRow(array &$summary, array $currentRows, array $otherRows): void
    {
        $currentCounts = $this->countLines($currentRows);
        $otherCounts = $this->countLines($otherRows);
        ksort($currentCounts, SORT_STRING);
        ksort($otherCounts, SORT_STRING);

        foreach ($otherCounts as $line => $n) {
            $extra = $n - ($currentCounts[$line] ?? 0);
            for ($i = 0; $i < $extra; $i++) {
                $summary['only_other']++;
                $this->addExample($summary['only_other_examples'], ['key' => null, 'row' => json_decode((string) $line, true)]);
            }
        }
        foreach ($currentCounts as $line => $n) {
            $extra = $n - ($otherCounts[$line] ?? 0);
            for ($i = 0; $i < $extra; $i++) {
                $summary['only_current']++;
                $this->addExample($summary['only_current_examples'], ['key' => null, 'row' => json_decode((string) $line, true)]);
            }
        }
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, int>
     */
    private function countLines(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $counts[$line] = ($counts[$line] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $columns
     * @return list<array<string, string>>
     */
    private function project(array $rows, array $columns): array
    {
        $flip = array_flip($columns);

        return array_map(fn (array $row) => array_intersect_key($row, $flip), $rows);
    }

    /** 例は最大 MAX_EXAMPLES 件。 */
    private function addExample(array &$examples, array $example): void
    {
        if (count($examples) < self::MAX_EXAMPLES) {
            $examples[] = $example;
        }
    }

    private function secondsDiff(string $other, string $current): ?int
    {
        if ($other === 'NULL' || $current === 'NULL') {
            return null;
        }

        return Carbon::parse($current)->getTimestamp() - Carbon::parse($other)->getTimestamp();
    }
}
