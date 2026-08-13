<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar_year.batch_generated (UC-C014: 定期バッチによる生成であることの印)。
 *
 * `generated_from`はUC-C011の即時生成・UC-C014のバッチ生成のいずれも`standard_template`で
 * 共通のため、実際にcron駆動のバッチから生成されたかどうかはこのイベントの記録有無で
 * 区別する(オンボーディング「今すぐ生成する」からはこのイベントを記録しない)。
 * 状態(company_calendar_years)自体は変更しないため、Projectorは持たない(監査目的のみ)。
 */
class CompanyCalendarYearBatchGenerated extends ShouldBeStored
{
    public function __construct() {}
}
