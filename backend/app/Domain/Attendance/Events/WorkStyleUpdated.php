<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * work_style.updated (勤務形態の設定内容を変更する。標準/システム生成の勤務形態も
 * 含め、code・is_default・system_generated以外の項目を変更できる)。
 */
class WorkStyleUpdated extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $attributes  更新で入力された属性だけ(変更しなかった属性は含まない)。
     */
    public function __construct(
        public readonly array $attributes,
        public readonly string $updatedByUserId,
    ) {}
}
