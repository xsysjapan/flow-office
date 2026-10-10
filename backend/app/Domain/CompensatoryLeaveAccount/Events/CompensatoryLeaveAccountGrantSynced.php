<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 休日出勤の計算結果から代休付与を同期する(作成・更新を兼ねる)。付与は下書き(draft)として記録する。
 * 同じ休日出勤日の下書きが既にあれば更新、無ければ作成する(CompensatoryLeaveAccountAggregate::syncGrantFromHolidayWork参照)。
 */
class CompensatoryLeaveAccountGrantSynced extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $sourceWorkDate,
        public readonly float $grantedDays,
        public readonly ?int $grantedMinutes,
    ) {}
}
