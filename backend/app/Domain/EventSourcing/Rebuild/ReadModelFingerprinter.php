<?php

namespace App\Domain\EventSourcing\Rebuild;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ReadModelの「件数」と「行の内容のハッシュ」(論点19 決定6。作り直し前のDBと、全ReadModelを空にして再生したDBの比較用)。
 *
 * 読み取りのみ。比較から除く列: created_at・updated_at、stored_events.id を指す列(EXCLUDED_COLUMNS)、
 * 自動採番の整数主キー(UUID等の主キーは比較に含める)。
 * 残った列を名前順に並べて各行を正規化した文字列にし、行の文字列をソートして連結し、sha256を取る。
 * DBの種類(MySQL/SQLite)に依らず同じ値になるよう、正規化はPHP側で行う。
 */
final class ReadModelFingerprinter
{
    /**
     * 比較から除く列。stored_events.id を指す列(マイグレーションの実際の列名。2026-10-13時点では stored_event_id のみ)と、時刻列。
     *
     * @var list<string>
     */
    public const EXCLUDED_COLUMNS = ['created_at', 'updated_at', 'stored_event_id'];

    private const CHUNK = 1000;

    /** @param  string|null  $connection 読む接続名(null は既定の接続) */
    public function __construct(private readonly ?string $connection = null) {}

    /** @return array{count: int, hash: string}|null テーブルが無ければ null */
    public function fingerprint(string $table): ?array
    {
        $lines = $this->rows($table);
        if ($lines === null) {
            return null;
        }

        return ['count' => count($lines), 'hash' => hash('sha256', implode("\n", $lines))];
    }

    /**
     * 正規化した行(ソート済み)。テーブルが無ければ null。
     *
     * @return list<string>|null
     */
    public function rows(string $table): ?array
    {
        $data = $this->normalizedRows($table);
        if ($data === null) {
            return null;
        }

        $lines = array_map(
            fn (array $normalized) => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $data['rows'],
        );
        sort($lines, SORT_STRING);

        return $lines;
    }

    /**
     * 比較対象の列だけを正規化した行(列名 => 文字列)。ReadModelDiffer が使う。テーブルが無ければ null。
     * key は、行の同一性に使える主キー列(自動採番の整数主キー・複合主キー・主キー無しは null)。
     * kinds は比較対象の列名 => 型の種別(integer/decimal/date/datetime/json_or_text/string)。
     *
     * @return array{key: string|null, kinds: array<string, string>, rows: list<array<string, string>>}|null
     */
    public function normalizedRows(string $table): ?array
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable($table)) {
            return null;
        }

        $columns = $schema->getColumns($table);
        $primary = $this->primaryKeyColumn($table);
        $autoIncrementPrimary = null;
        foreach ($columns as $column) {
            if ($column['name'] === $primary && ($column['auto_increment'] ?? false)
                && $this->kind($column['type_name']) === 'integer') {
                $autoIncrementPrimary = $primary;
            }
        }

        $types = [];
        foreach ($columns as $column) {
            $name = $column['name'];
            if (in_array($name, self::EXCLUDED_COLUMNS, true) || $name === $autoIncrementPrimary) {
                continue;
            }
            $types[$name] = $this->kind($column['type_name']);
        }
        ksort($types);

        $result = [];
        $handle = function ($rows) use ($types, &$result): void {
            foreach ($rows as $row) {
                $row = (array) $row;
                $normalized = [];
                foreach ($types as $name => $kind) {
                    $normalized[$name] = $this->normalize($row[$name] ?? null, $kind);
                }
                $result[] = $normalized;
            }
        };

        $db = DB::connection($this->connection);
        if ($primary !== null) {
            $db->table($table)->chunkById(self::CHUNK, $handle, $primary);
        } else {
            $query = $db->table($table);
            foreach (array_column($columns, 'name') as $name) {
                $query->orderBy($name);
            }
            $query->chunk(self::CHUNK, $handle);
        }

        $key = ($primary !== null && $primary !== $autoIncrementPrimary && isset($types[$primary])) ? $primary : null;

        return ['key' => $key, 'kinds' => $types, 'rows' => $result];
    }

    /** 主キーが1列ならその列名(複合主キー・主キー無しは null)。 */
    private function primaryKeyColumn(string $table): ?string
    {
        foreach (Schema::connection($this->connection)->getIndexes($table) as $index) {
            if (($index['primary'] ?? false) && count($index['columns']) === 1) {
                return $index['columns'][0];
            }
        }

        return null;
    }

    private function kind(string $typeName): string
    {
        $type = strtolower($typeName);
        if (in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'boolean', 'bool'], true)) {
            return 'integer';
        }
        if (in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'double precision'], true)) {
            return 'decimal';
        }
        if ($type === 'date') {
            return 'date';
        }
        if (str_contains($type, 'time')) {
            return 'datetime';
        }
        if (in_array($type, ['json', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
            return 'json_or_text';
        }

        return 'string';
    }

    private function normalize(mixed $value, string $kind): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return match ($kind) {
            'integer' => (string) (int) $value,
            'decimal' => $this->normalizeDecimal($value),
            'date' => substr((string) $value, 0, 10),
            'datetime' => Carbon::parse((string) $value)->format('Y-m-d H:i:s'),
            'json_or_text' => $this->normalizeJsonOrText((string) $value),
            default => (string) $value,
        };
    }

    private function normalizeDecimal(mixed $value): string
    {
        $text = is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1
            ? $value
            : number_format((float) $value, 10, '.', '');
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' || $text === '' ? '0' : $text;
    }

    /** JSONとして読める文字列は、キー順・空白の違いを吸収して書き直す(MySQLのJSON型とSQLiteのTEXTの差)。 */
    private function normalizeJsonOrText(string $value): string
    {
        if ($value === '' || ! in_array($value[0], ['{', '['], true)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return $value;
        }

        return json_encode($this->sortKeys($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sortKeys(array $data): array
    {
        foreach ($data as $key => $item) {
            if (is_array($item)) {
                $data[$key] = $this->sortKeys($item);
            }
        }
        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }
}
