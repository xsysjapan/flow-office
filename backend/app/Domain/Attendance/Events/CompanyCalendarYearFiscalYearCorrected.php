<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar_year.fiscal_year_corrected
 *
 * 公開誤り等で年度番号を取り違えて公開してしまったカレンダー年度を、事後的に強制修正する。
 * 通常の年度は作成時にfiscalYearが確定し変更手段を持たない(CompanyCalendarYearCreated参照)が、
 * 既に実績日(attendance_days等)が積まれた年度を差し戻し→作り直しさせるのは現実的でないため、
 * ステータスを問わず年度番号・開始日・終了日を訂正できる専用イベントとして追記する
 * (既存のCompanyCalendarYearCreated等は書き換えない。root CLAUDE.md 原則13)。
 */
class CompanyCalendarYearFiscalYearCorrected extends ShouldBeStored
{
    public function __construct(
        public readonly int $fiscalYear,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $correctedByUserId,
        public readonly ?string $reason,
    ) {}
}
