<?php

namespace App\Console\Commands\Legacy;

use App\Domain\LegacyMigration\UuidMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * 本番カットオーバー移行(docs/30-legacy-data-migration.md)手順2: `legacy:export`が
 * 書き出したJSONスナップショット(旧`stored_events`本体を含む)を読み、
 * `config/legacy_migration.php`の`aggregates`定義に従って、各集約の**実際の履歴イベントを
 * できる限り忠実に**新スキーマの`stored_events`へ変換してINSERTする。
 *
 * 集約ごとの処理:
 * 1. 旧`stored_events`から、その集約(aggregate_type)の全イベントを対象のaggregate_id
 *    (旧int)ごとにグルーピングし、versionの昇順に並べる。
 * 2. `always_genesis`が真、またはその集約に旧イベントが1件も無い場合、`genesis`クロージャで
 *    「移行時点で分かる最も古い状態」を最初のイベントとして合成する(旧システムでは
 *    エンティティの新規作成自体がイベント化されていないドメイン(User等)がこれに該当する)。
 * 3. 旧イベントを1件ずつ、`events[旧event_type]`クロージャで新イベントへ変換する。
 *    クロージャは`$state`(このイベントストリームを通して引き継がれる可変の連想配列)を
 *    受け取り更新できる。旧`user.synced_from_ms365`(差分のみを持つ)のように、旧payload
 *    だけでは新イベントに必要な全フィールドを復元できない場合、`$state`に前回までの
 *    既知の値を積み上げて補う。マッピングが無い・対応不能なイベント種別はスキップし、
 *    警告を出す(移行全体を止めない)。
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

    /** @var array<int, string> スキップしたイベントの内訳(--dry-run/実行後に表示する) */
    private array $skipped = [];

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
        $definitions = $config['aggregates'];
        $order = $this->resolveOrder($definitions);

        if (! $dryRun) {
            $this->copyPlainTables($path, $config['plain_copy_tables'] ?? []);
        }

        $uuidMap = UuidMap::load($mapPath);

        // 旧stored_events本体を、aggregate_type ごとにグルーピングして読み込む。
        $legacyEventsByAggregateType = $this->loadLegacyEvents($path);

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

        foreach ($order as $aggregateType) {
            $definition = $definitions[$aggregateType];
            $currentRows = $rowsByTable[$definition['table']];
            $currentRowsById = [];
            foreach ($currentRows as $row) {
                $currentRowsById[(string) $row->id] = $row;
            }

            $childrenByParentId = [];
            foreach ($definition['children'] ?? [] as $childKey => $child) {
                $childrenByParentId[$childKey] = [];
                foreach ($rowsByTable[$child['table']] as $childRow) {
                    $parentId = (string) $childRow->{$child['parent_column']};
                    $childrenByParentId[$childKey][$parentId][] = $childRow;
                }
            }

            $oldEventsByAggregateId = $legacyEventsByAggregateType[$aggregateType] ?? [];

            $allIds = array_unique(array_merge(array_keys($currentRowsById), array_keys($oldEventsByAggregateId)));
            sort($allIds, SORT_STRING);

            $count = 0;
            foreach ($allIds as $legacyId) {
                $newUuid = $uuidMap->resolve($definition['table'], $legacyId);
                $currentRow = $currentRowsById[$legacyId] ?? null;

                $children = [];
                foreach (array_keys($definition['children'] ?? []) as $childKey) {
                    $children[$childKey] = $childrenByParentId[$childKey][$legacyId] ?? [];
                }

                $oldEvents = $oldEventsByAggregateId[$legacyId] ?? [];
                $state = [];
                $version = 0;
                $needsGenesis = ($definition['always_genesis'] ?? false) || $oldEvents === [];

                if ($needsGenesis && isset($definition['genesis'])) {
                    $result = ($definition['genesis'])($currentRow, $uuidMap, $actorUuid, $state);
                    $state = $result['state'] ?? $state;
                    $version++;
                    $events[] = $this->buildEventRow(
                        $newUuid,
                        $version,
                        $this->shortEventClass($result['event_class']),
                        $result['properties'],
                        $aggregateType,
                        $legacyId,
                        $currentRow->created_at ?? now(),
                    );
                    $count++;
                }

                foreach ($oldEvents as $oldEvent) {
                    $mapper = $definition['events'][$oldEvent->event_type] ?? null;
                    if ($mapper === null) {
                        $this->skipped[] = "{$aggregateType}#{$legacyId} v{$oldEvent->version} ({$oldEvent->event_type}): マッピング未定義";

                        continue;
                    }

                    $payload = json_decode($oldEvent->payload, true) ?? [];
                    $result = $mapper($payload, $uuidMap, $currentRow, $actorUuid, $oldEvent->occurred_at, $state, $children);

                    if ($result === null) {
                        $this->skipped[] = "{$aggregateType}#{$legacyId} v{$oldEvent->version} ({$oldEvent->event_type}): 変換不能としてスキップ";

                        continue;
                    }

                    $state = $result['state'] ?? $state;
                    if (! isset($result['properties'])) {
                        // このイベント自体は新イベントを生まない(stateの更新のみ)。
                        continue;
                    }

                    $version++;
                    $events[] = $this->buildEventRow(
                        $newUuid,
                        $version,
                        $this->shortEventClass($result['event_class']),
                        $result['properties'],
                        $aggregateType,
                        $legacyId,
                        $oldEvent->occurred_at,
                    );
                    $count++;
                }
            }

            $this->info("{$aggregateType}: {$count}件のイベント (対象{$definition['table']}: ".count($allIds).'件)');
        }

        $uuidMap->save();

        if ($this->skipped !== []) {
            $this->warn(count($this->skipped).'件のイベントをマッピング未対応としてスキップしました:');
            foreach (array_slice($this->skipped, 0, 50) as $line) {
                $this->line("  - {$line}");
            }
            if (count($this->skipped) > 50) {
                $this->line('  ...(以下省略)');
            }
        }

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
     * 旧`stored_events`スナップショットを読み、aggregate_type -> aggregate_id(文字列) ->
     * version昇順のイベント配列、という形にグルーピングする。
     *
     * @return array<string, array<string, array<int, stdClass>>>
     */
    private function loadLegacyEvents(string $path): array
    {
        $rows = $this->readSnapshot($path, 'stored_events');

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->aggregate_type][(string) $row->aggregate_id][] = $row;
        }

        foreach ($grouped as $aggregateType => $byId) {
            foreach ($byId as $id => $events) {
                usort($events, fn (stdClass $a, stdClass $b) => $a->version <=> $b->version);
                $grouped[$aggregateType][$id] = $events;
            }
        }

        return $grouped;
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
        string $legacyAggregateType,
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
                    'aggregate_type' => $legacyAggregateType,
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
                throw new RuntimeException("legacy_migration.aggregates に循環依存があります: {$key}");
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
