<?php

namespace App\Console\Commands\Legacy;

use App\Domain\LegacyMigration\UuidMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * 本番カットオーバー移行(docs/30-legacy-data-migration.md)手順2: `legacy:export`が
 * 書き出したJSONスナップショットを読み、`config/legacy_migration.php`の定義に従って
 * 新スキーマのUUIDへ変換した`stored_events`行を直接INSERTする。
 *
 * このコマンドは新DB(デフォルト接続)にのみ書き込む。実行前に`stored_events`が空である
 * ことを前提とする(`--force`を付けない限り、既に行があれば中断する)。
 *
 * 実行後は `php artisan event-sourcing:replay` で全Projectionを再生成すること
 * (このコマンド自体はProjectionを更新しない)。
 */
class LegacyConvertSnapshotCommand extends Command
{
    protected $signature = 'legacy:convert
        {--path= : legacy:exportの出力先ディレクトリ}
        {--map= : UUID対応表(JSON)の保存先ファイル}
        {--force : stored_eventsに既に行があっても続行する}
        {--dry-run : DBに書き込まず、変換結果の件数だけ表示する}';

    protected $description = 'legacy:exportのスナップショットをstored_events用のイベントへ変換してINSERTする';

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/legacy-migration/snapshot');
        $mapPath = $this->option('map') ?: storage_path('app/legacy-migration/uuid-map.json');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && DB::table('stored_events')->exists()) {
            $this->error('stored_events に既に行があります。--force を付けて実行するか、先に空にしてください。');

            return self::FAILURE;
        }

        $config = config('legacy_migration');
        $definitions = $config['tables'];
        $order = $this->resolveOrder($definitions);

        if (! $dryRun) {
            $this->copyPlainTables($path, $config['plain_copy_tables'] ?? []);
        }

        $uuidMap = UuidMap::load($mapPath);

        $rowsByTable = [];
        foreach ($order as $key) {
            $rowsByTable[$definitions[$key]['table']] = $this->readSnapshot($path, $definitions[$key]['table']);
            foreach ($definitions[$key]['children'] ?? [] as $child) {
                $rowsByTable[$child['table']] = $this->readSnapshot($path, $child['table']);
            }
        }

        $actorTable = $definitions[$config['actor_table']]['table'];
        $actorRows = $rowsByTable[$actorTable] ?? [];
        if ($actorRows === []) {
            $this->error("移行実行者を決めるための [{$actorTable}] が空です。");

            return self::FAILURE;
        }
        $actorUuid = $uuidMap->resolve($actorTable, $actorRows[0]->id);
        $this->info("移行実行者(createdByUserId等): {$actorTable}#{$actorRows[0]->id} -> {$actorUuid}");

        $events = [];

        foreach ($order as $key) {
            $definition = $definitions[$key];
            $rows = $rowsByTable[$definition['table']];

            $childrenByParentId = [];
            foreach ($definition['children'] ?? [] as $childKey => $child) {
                $childrenByParentId[$childKey] = [];
                foreach ($rowsByTable[$child['table']] as $childRow) {
                    $parentId = $childRow->{$child['parent_column']};
                    $childrenByParentId[$childKey][$parentId][] = $childRow;
                }
            }

            $count = 0;
            foreach ($rows as $row) {
                $newUuid = $uuidMap->resolve($definition['table'], $row->id);

                $children = [];
                foreach (array_keys($definition['children'] ?? []) as $childKey) {
                    $children[$childKey] = $childrenByParentId[$childKey][$row->id] ?? [];
                }

                $properties = ($definition['map'])($row, $uuidMap, $actorUuid, $children);
                $version = 1;

                $events[] = $this->buildEventRow(
                    $newUuid,
                    $version,
                    $this->shortEventClass($definition['event_class']),
                    $properties,
                    $definition['table'],
                    $row->id,
                    $row->created_at ?? now(),
                );
                $count++;

                foreach (($definition['extra_events'] ?? fn () => [])($row, $uuidMap, $actorUuid, $children) as $extra) {
                    $version++;
                    $events[] = $this->buildEventRow(
                        $newUuid,
                        $version,
                        $this->shortEventClass($extra['event_class']),
                        $extra['properties'],
                        $definition['table'],
                        $row->id,
                        $row->created_at ?? now(),
                    );
                    $count++;
                }
            }

            $this->info("{$definition['table']}: {$count}件 -> {$definition['event_class']}");
        }

        $uuidMap->save();

        if ($dryRun) {
            $this->info('--dry-run のためDBへは書き込んでいません。合計 '.count($events).' 件のイベントを生成しました。');

            return self::SUCCESS;
        }

        foreach (array_chunk($events, 500) as $chunk) {
            DB::table('stored_events')->insert($chunk);
        }

        $this->info('完了。合計 '.count($events)." 件のイベントを stored_events へ書き込みました。UUID対応表: {$mapPath}");
        $this->info('次に `php artisan event-sourcing:replay` を実行してProjectionを再生成してください。');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function buildEventRow(
        string $aggregateUuid,
        int $version,
        string $shortEventClass,
        array $properties,
        string $legacyTable,
        int|string $legacyId,
        mixed $createdAt,
    ): array {
        return [
            'aggregate_uuid' => $aggregateUuid,
            'aggregate_version' => $version,
            'event_version' => 1,
            'event_class' => $shortEventClass,
            'event_properties' => json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta_data' => json_encode([
                'aggregate-root-uuid' => $aggregateUuid,
                'aggregate-root-version' => $version,
                'legacy_migration' => [
                    'table' => $legacyTable,
                    'legacy_id' => $legacyId,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $createdAt,
        ];
    }

    /**
     * イベントソーシング対象外のマスタテーブル(config('legacy_migration.plain_copy_tables'))を、
     * 新DB側の中身を一旦全削除した上で、旧DBのスナップショットをそのままINSERTし直す。
     * 主キーの型は変わっていないため変換不要(seederが入れた既定行より、旧DBの実データを正とする)。
     *
     * @param  array<int, string>  $tables
     */
    private function copyPlainTables(string $path, array $tables): void
    {
        foreach ($tables as $table) {
            $rows = $this->readSnapshot($path, $table);

            DB::table($table)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert(array_map(fn (stdClass $row) => (array) $row, $chunk));
            }

            $this->info("[plain copy] {$table}: ".count($rows).'件');
        }
    }

    /**
     * @return array<int, stdClass>
     */
    private function readSnapshot(string $path, string $table): array
    {
        $file = "{$path}/{$table}.json";
        if (! file_exists($file)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($file));

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * depends_on を見て、依存先が先に来る順番に並べ替える(単純なトポロジカルソート)。
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<int, string>
     */
    private function resolveOrder(array $definitions): array
    {
        $resolved = [];
        $visiting = [];

        $visit = function (string $key) use (&$visit, &$resolved, &$visiting, $definitions): void {
            if (in_array($key, $resolved, true)) {
                return;
            }
            if (isset($visiting[$key])) {
                throw new RuntimeException("legacy_migration.tables に循環依存があります: {$key}");
            }
            $visiting[$key] = true;

            foreach ($definitions[$key]['depends_on'] ?? [] as $dependency) {
                $visit($dependency);
            }

            unset($visiting[$key]);
            $resolved[] = $key;
        };

        foreach (array_keys($definitions) as $key) {
            $visit($key);
        }

        return $resolved;
    }

    private function shortEventClass(string $eventClass): string
    {
        $map = config('event-sourcing.event_class_map', []);
        $short = array_search($eventClass, $map, true);

        if ($short === false) {
            throw new RuntimeException("{$eventClass} が config/event-sourcing.php の event_class_map に登録されていません。");
        }

        return $short;
    }
}
