<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * holiday_calendar_source.registered (UC-C012 手順1: 祝日iCalendarソースを登録する)。
 * 集約ID(holiday_calendar_sources.id)は`aggregateRootUuid()`から取得する。
 */
class HolidayCalendarSourceRegistered extends ShouldBeStored
{
    /**
     * `sourceKind`/`uploadedIcsPath`/`uploadedIcsFilename`はICSアップロード対応で後から
     * 追加したフィールド。既存の`stored_events`(登録時点ではURL登録しか無かった)には
     * これらのキーが存在しないため、デシリアライズ時にJsonEventSerializerが復元できるよう
     * 必ずデフォルト値を持たせる(既定値なしの必須引数を追加すると、過去イベントの
     * 再生・APIからの参照時に「Failed to unserialize」で壊れる)。
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $icsUrl,
        public readonly string $registeredByUserId,
        public readonly string $sourceKind = 'url',
        public readonly ?string $uploadedIcsPath = null,
        public readonly ?string $uploadedIcsFilename = null,
    ) {}
}
