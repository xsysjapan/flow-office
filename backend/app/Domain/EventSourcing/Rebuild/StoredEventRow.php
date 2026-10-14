<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * stored_events の生の1行。旧系統のイベントクラスに依存せず、event_class の文字列とJSONをそのまま読む
 * (論点18 assets/event-rebuild-mapping.md 7章: 旧クラスは作り直しの前に削除されるため)。
 */
final readonly class StoredEventRow
{
    /**
     * @param  array<string, mixed>  $payload  event_properties(キーはイベントの公開プロパティ名のcamelCase)
     * @param  array<string, mixed>  $meta  meta_data
     */
    public function __construct(
        public int $id,
        public ?string $aggregateUuid,
        public ?int $aggregateVersion,
        public string $eventClass,
        public array $payload,
        public array $meta,
        public string $createdAt,
    ) {}

    /** DB::table('stored_events') の1行(stdClass)から作る。 */
    public static function fromDatabaseRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            aggregateUuid: $row->aggregate_uuid !== null ? (string) $row->aggregate_uuid : null,
            aggregateVersion: $row->aggregate_version !== null ? (int) $row->aggregate_version : null,
            eventClass: (string) $row->event_class,
            payload: self::decode($row->event_properties ?? null),
            meta: self::decode($row->meta_data ?? null),
            createdAt: (string) $row->created_at,
        );
    }

    /** meta_data の created-at(Spatieの recordThat 時刻)。無い・文字列でないときは null。 */
    public function metaCreatedAt(): ?string
    {
        $value = $this->meta['created-at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * 作り直し後のイベントの発生日時(論点19 決定10-8。決定8-5・9-4を置き換える)。meta_data の created-at を常に使う
     * (Spatie の ShouldBeStored::createdAt() で、Projector が読む時刻)。列 created_at は保存時刻で、旧版の正規化・保守の
     * コマンドが後から書き換えた行があるため使わない。created-at が無い・解釈できないときだけ列の値を使う。
     * 書式は meta の値をそのまま写す。
     */
    public function occurredAt(): string
    {
        $meta = $this->metaCreatedAt();
        if ($meta === null || strtotime($meta) === false) {
            return $this->createdAt;
        }

        return $meta;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (is_array($json)) {
            return $json;
        }

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }
}
