<?php

namespace App\Domain\PaidLeaveSchedule\Projectors;

use App\Domain\Attendance\Events\CompanyCalendarCreated;
use App\Domain\Attendance\Events\CompanyCalendarDefaultChanged;
use App\Domain\Attendance\Events\CompanyCalendarDeleted;
use App\Domain\Attendance\Events\CompanyCalendarUpdated;
use App\Domain\PaidLeaveSchedule\Support\FiscalYearStartResolver;
use App\Models\PaidLeaveFiscalYearSetting;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 既定の会社カレンダー(勤怠の文脈)の年度開始月日を、有給(付与予定)の文脈の読み取りビュー
 * `paid_leave_fiscal_year_settings`(1行)へ写す(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点3)。
 * 勤怠の文脈のテーブルは読まず、イベントだけから作る。
 *
 * 年度開始月日、または既定のカレンダーが実際に変わったときだけ、`last_changed_event_id`をそのイベントのidにする
 * (`ReapplyScheduleOnFiscalYearStartChangedReactor`が、名前だけの変更と区別するために使う)。
 */
class PaidLeaveFiscalYearSettingsProjector extends Projector
{
    /**
     * 作成の時点では既定かどうか分からない(最初の1件は続く`CompanyCalendarDefaultChanged`で既定になる)。
     * すでに既定のカレンダーなら、年度開始月日だけ写す。
     */
    public function onCompanyCalendarCreated(CompanyCalendarCreated $event): void
    {
        $this->syncIfDefault($event->aggregateRootUuid(), $event->fiscalYearStartMonth, $event->fiscalYearStartDay, $event);
    }

    public function onCompanyCalendarUpdated(CompanyCalendarUpdated $event): void
    {
        $this->syncIfDefault($event->aggregateRootUuid(), $event->fiscalYearStartMonth, $event->fiscalYearStartDay, $event);
    }

    /**
     * 新しい既定のカレンダーの年度開始月日は、そのカレンダーの作成・更新のイベント(このイベントより前の最新)から読む。
     */
    public function onCompanyCalendarDefaultChanged(CompanyCalendarDefaultChanged $event): void
    {
        $calendarId = $event->aggregateRootUuid();

        $latest = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $calendarId)
            ->whereIn('event_class', ['company_calendar.created', 'company_calendar.updated'])
            ->when($event->storedEventId() !== null, fn ($query) => $query->where('id', '<=', $event->storedEventId()))
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return;
        }

        $properties = $latest->event_properties;

        $this->write(
            $calendarId,
            (int) $properties['fiscalYearStartMonth'],
            (int) $properties['fiscalYearStartDay'],
            $event,
        );
    }

    /**
     * 既定のカレンダーが削除されたら、既定のカレンダーなし(4月1日)に戻す。
     */
    public function onCompanyCalendarDeleted(CompanyCalendarDeleted $event): void
    {
        $setting = PaidLeaveFiscalYearSetting::query()->find(PaidLeaveFiscalYearSetting::SINGLETON_ID);

        if ($setting === null || $setting->company_calendar_id !== $event->aggregateRootUuid()) {
            return;
        }

        $this->write(null, FiscalYearStartResolver::DEFAULT_MONTH, FiscalYearStartResolver::DEFAULT_DAY, $event);
    }

    private function syncIfDefault(string $calendarId, int $month, int $day, ShouldBeStored $event): void
    {
        $setting = PaidLeaveFiscalYearSetting::query()->find(PaidLeaveFiscalYearSetting::SINGLETON_ID);

        if ($setting === null || $setting->company_calendar_id !== $calendarId) {
            return;
        }

        $this->write($calendarId, $month, $day, $event);
    }

    private function write(?string $calendarId, int $month, int $day, ShouldBeStored $event): void
    {
        $setting = PaidLeaveFiscalYearSetting::query()->find(PaidLeaveFiscalYearSetting::SINGLETON_ID);

        $changed = $setting === null
            || $setting->company_calendar_id !== $calendarId
            || (int) $setting->fiscal_year_start_month !== $month
            || (int) $setting->fiscal_year_start_day !== $day;

        PaidLeaveFiscalYearSetting::query()->updateOrCreate(
            ['id' => PaidLeaveFiscalYearSetting::SINGLETON_ID],
            [
                'company_calendar_id' => $calendarId,
                'fiscal_year_start_month' => $month,
                'fiscal_year_start_day' => $day,
                'last_changed_event_id' => $changed ? $event->storedEventId() : $setting?->last_changed_event_id,
            ],
        );
    }
}
