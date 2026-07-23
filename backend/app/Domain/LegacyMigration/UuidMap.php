<?php

namespace App\Domain\LegacyMigration;

use Illuminate\Support\Str;

/**
 * 本番カットオーバー移行(docs/30-legacy-data-migration.md)専用。旧スキーマの整数PKと、
 * 新スキーマで割り当てるUUIDの対応表を1ファイル(JSON)で管理する。
 *
 * `legacy:export` → `legacy:convert` の間で何度スクリプトを実行し直しても、同じ
 * (テーブル名, 旧id)の組には常に同じUUIDを割り当てる(冪等)。これにより、他テーブルの
 * 外部キー変換時に必ず同じUUIDを参照でき、`legacy:convert`を安全に再実行できる。
 */
class UuidMap
{
    /** @var array<string, array<string, string>> テーブル名 => (旧id => 新UUID) */
    private array $map;

    private function __construct(private readonly string $path, array $map)
    {
        $this->map = $map;
    }

    public static function load(string $path): self
    {
        if (! file_exists($path)) {
            return new self($path, []);
        }

        $contents = file_get_contents($path);
        $decoded = $contents !== '' ? json_decode($contents, true) : [];

        return new self($path, is_array($decoded) ? $decoded : []);
    }

    /**
     * 指定した(テーブル, 旧id)に対応するUUIDを返す。未割り当てなら新規発行して記録する。
     */
    public function resolve(string $table, int|string $legacyId): string
    {
        $key = (string) $legacyId;

        if (! isset($this->map[$table])) {
            $this->map[$table] = [];
        }

        if (! isset($this->map[$table][$key])) {
            $this->map[$table][$key] = (string) Str::orderedUuid();
        }

        return $this->map[$table][$key];
    }

    /**
     * 既に割り当て済みかどうかだけを確認する(発行はしない)。外部キーが本当に旧テーブルに
     * 存在する行を指しているかの整合性チェック用。
     */
    public function has(string $table, int|string $legacyId): bool
    {
        return isset($this->map[$table][(string) $legacyId]);
    }

    public function save(): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->path, json_encode($this->map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, string>
     */
    public function tableMap(string $table): array
    {
        return $this->map[$table] ?? [];
    }
}
