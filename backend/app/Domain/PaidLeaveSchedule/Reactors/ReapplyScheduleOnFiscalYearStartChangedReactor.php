<?php

namespace App\Domain\PaidLeaveSchedule\Reactors;

use App\Domain\Attendance\Events\CompanyCalendarCreated;
use App\Domain\Attendance\Events\CompanyCalendarDefaultChanged;
use App\Domain\Attendance\Events\CompanyCalendarDeleted;
use App\Domain\Attendance\Events\CompanyCalendarUpdated;
use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\PaidLeaveFiscalYearSetting;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 既定の会社カレンダーの年度開始日(有給の一斉付与日)が変わったとき、未確定の付与予定を作り直す
 * (`ReapplyPaidLeaveSchedulePolicyJob`を発行する。docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点3)。
 *
 * 同じイベントで先に動く`PaidLeaveFiscalYearSettingsProjector`が、実際に変わったときだけビューの
 * `last_changed_event_id`をそのイベントのidにする。一致したときだけ発行するので、名前だけの変更など、
 * 年度開始と関係の無い更新では発火しない。
 */
class ReapplyScheduleOnFiscalYearStartChangedReactor extends Reactor
{
    public function onCompanyCalendarCreated(CompanyCalendarCreated $event): void
    {
        $this->reapplyIfChangedBy($event);
    }

    public function onCompanyCalendarUpdated(CompanyCalendarUpdated $event): void
    {
        $this->reapplyIfChangedBy($event);
    }

    public function onCompanyCalendarDefaultChanged(CompanyCalendarDefaultChanged $event): void
    {
        $this->reapplyIfChangedBy($event);
    }

    public function onCompanyCalendarDeleted(CompanyCalendarDeleted $event): void
    {
        $this->reapplyIfChangedBy($event);
    }

    private function reapplyIfChangedBy(ShouldBeStored $event): void
    {
        $storedEventId = $event->storedEventId();

        if ($storedEventId === null) {
            return;
        }

        $setting = PaidLeaveFiscalYearSetting::query()->find(PaidLeaveFiscalYearSetting::SINGLETON_ID);

        if ($setting === null || (int) $setting->last_changed_event_id !== (int) $storedEventId) {
            return;
        }

        ReapplyPaidLeaveSchedulePolicyJob::dispatch('既定の会社カレンダーの年度開始日の変更');
    }
}
