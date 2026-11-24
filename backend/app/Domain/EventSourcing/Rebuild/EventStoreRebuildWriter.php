<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * stored_events と同じ構造の表を作って書く(BooleanPropertyMigrator が、SQLiteでバックアップ表を作るために使う)。
 *
 * - write(): 新しい表(既定 stored_events_rebuilt)に書く。既にあれば失敗する。
 */
final class EventStoreRebuildWriter
{
    public const TABLE = 'stored_events_rebuilt';

    private const CHUNK = 500;

    /**
     * 新しい表に書く。stored_events と同じ構造(id は変換で振った値をそのまま使う)。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function write(array $rows, string $table = self::TABLE): void
    {
        if (Schema::hasTable($table)) {
            throw new RuntimeException("{$table} が既に存在します。");
        }

        // SQLiteの索引名はデータベース全体で一意。swap後の stored_events は前回の作り直し表の索引名を持ち続けるので、同名なら避ける。
        $indexName = "{$table}_stream_unique";
        if (collect(Schema::getIndexes('stored_events'))->pluck('name')->contains($indexName)) {
            $indexName .= '_next';
        }

        Schema::create($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->id();
            $blueprint->uuid('aggregate_uuid')->nullable();
            $blueprint->unsignedBigInteger('aggregate_version')->nullable();
            $blueprint->unsignedTinyInteger('event_version')->default(1);
            $blueprint->string('event_class');
            $blueprint->json('event_properties');
            $blueprint->json('meta_data');
            $blueprint->timestamp('created_at');
            $blueprint->unique(['aggregate_uuid', 'aggregate_version'], $indexName);
        });

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert(array_map(fn (array $row) => [
                'id' => $row['id'],
                'aggregate_uuid' => $row['aggregate_uuid'],
                'aggregate_version' => $row['aggregate_version'],
                'event_version' => $row['event_version'],
                'event_class' => $row['event_class'],
                'event_properties' => json_encode($row['event_properties'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'meta_data' => json_encode($row['meta_data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $row['created_at'],
            ], $chunk));
        }
    }
}
