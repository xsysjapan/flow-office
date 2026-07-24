<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * work_style.default_changed (指示書 3.2節: 会社のデフォルト働き方は常に1件。
 * 新しい働き方をデフォルトに設定した場合は既存のデフォルトを解除する)。
 * このイベントの集約ID(`aggregateRootUuid()`)が新しいデフォルトのwork_style_id。
 */
class WorkStyleDefaultChanged extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $previousDefaultWorkStyleId,
        public readonly string $changedByUserId,
    ) {}
}
