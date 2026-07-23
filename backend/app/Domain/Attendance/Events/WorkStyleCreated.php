<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * work_style.created (UC-C002: 勤務形態を作成する)。集約ID(work_styles.id)は
 * `aggregateRootUuid()`から取得する。
 */
class WorkStyleCreated extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $attributes  作成時のwork_styles属性一式。
     */
    public function __construct(
        public readonly array $attributes,
        public readonly string $createdByUserId,
    ) {}
}
