<?php

namespace App\Console\Commands\Legacy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 本番カットオーバー移行(docs/30-legacy-data-migration.md)手順1: `legacy`接続
 * (config/database.phpの`legacy`、旧スキーマのDB)から、`config/legacy_migration.php`に
 * 定義された全テーブルの現在の行をそのままJSONへ書き出す。
 *
 * このコマンド自体は新スキーマに一切書き込まない(読み取り専用)。書き出したJSONは
 * `legacy:convert`が読み込む。
 */
class LegacyExportSnapshotCommand extends Command
{
    protected $signature = 'legacy:export {--connection=legacy} {--path=}';

    protected $description = '旧スキーマDBの現在の行をJSONスナップショットへ書き出す(読み取り専用)';

    public function handle(): int
    {
        $connection = $this->option('connection');
        $path = $this->option('path') ?: storage_path('app/legacy-migration/snapshot');

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $definitions = config('legacy_migration.tables');
        $tablesToExport = [];

        foreach (config('legacy_migration.plain_copy_tables', []) as $table) {
            $tablesToExport[$table] = true;
        }

        foreach ($definitions as $definition) {
            $tablesToExport[$definition['table']] = true;
            foreach ($definition['children'] ?? [] as $child) {
                $tablesToExport[$child['table']] = true;
            }
        }

        foreach (array_keys($tablesToExport) as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                $this->warn("[skip] {$table} は接続 [{$connection}] に存在しません。");

                continue;
            }

            $rows = DB::connection($connection)->table($table)->orderBy('id')->get();
            $file = "{$path}/{$table}.json";
            file_put_contents($file, $rows->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $this->info("{$table}: {$rows->count()}件 -> {$file}");
        }

        $this->info("完了。スナップショットの出力先: {$path}");

        return self::SUCCESS;
    }
}
