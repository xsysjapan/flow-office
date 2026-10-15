<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventSerializers\EventSerializer;
use Throwable;

/**
 * stored_events(またはその作り直し表)の各行を、Spatieの仕組みで現行のイベントクラスへ復元できるか試す(読み取りのみ。DBには書かない)。
 * 復元できない行(古いpayloadなど)は event:replay で InvalidStoredEvent になるため、事前に種別ごとに洗い出す。
 *
 * 結果は event_class => ['count' => 件数, 'first_id' => 先頭のid, 'message' => 最初の例外メッセージ(1行)]。
 */
final class EventRestorabilityChecker
{
    private const CHUNK = 500;

    /**
     * @return array<string, array{count: int, first_id: int, message: string}>
     */
    public function checkTable(string $table): array
    {
        $failures = [];
        DB::table($table)->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$failures): void {
            foreach ($chunk as $row) {
                $this->checkOne($failures, (int) $row->id, (string) $row->event_class, (int) ($row->event_version ?? 1), $row->event_properties, $row->meta_data ?? null);
            }
        });

        return $failures;
    }

    /**
     * 作り直し後の行(DBに書く前のメモリ上の結果。RebuildResult::$rows)を検査する。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{count: int, first_id: int, message: string}>
     */
    public function checkRows(array $rows): array
    {
        $failures = [];
        foreach ($rows as $row) {
            $this->checkOne(
                $failures,
                (int) $row['id'],
                (string) $row['event_class'],
                (int) ($row['event_version'] ?? 1),
                json_encode($row['event_properties'], JSON_UNESCAPED_UNICODE),
                json_encode($row['meta_data'] ?? [], JSON_UNESCAPED_UNICODE),
            );
        }

        return $failures;
    }

    /** @param  array<string, array{count: int, first_id: int, message: string}>  $failures */
    private function checkOne(array &$failures, int $id, string $eventClass, int $eventVersion, mixed $properties, mixed $meta): void
    {
        $class = config('event-sourcing.event_class_map', [])[$eventClass] ?? $eventClass;

        try {
            app(EventSerializer::class)->deserialize(
                $class,
                is_string($properties) ? $properties : json_encode($properties),
                $eventVersion,
                $meta === null ? null : (is_string($meta) ? $meta : json_encode($meta)),
            );
        } catch (Throwable $e) {
            if (! isset($failures[$eventClass])) {
                $message = preg_replace('/\s+/u', ' ', trim($e->getMessage())) ?? '';
                $failures[$eventClass] = ['count' => 0, 'first_id' => $id, 'message' => mb_strimwidth($message, 0, 200, '...')];
            }
            $failures[$eventClass]['count']++;
        }
    }
}
