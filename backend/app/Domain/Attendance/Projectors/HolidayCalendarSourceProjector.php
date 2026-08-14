<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Services\CalendarWeekdayPatternDayGenerator;
use App\Domain\Attendance\Events\HolidayCalendarSourceDeleted;
use App\Domain\Attendance\Events\HolidayCalendarSourceDisabled;
use App\Domain\Attendance\Events\HolidayCalendarSourceRegistered;
use App\Domain\Attendance\Events\HolidayCalendarSourceSynced;
use App\Domain\Attendance\Events\HolidayCalendarSourceSyncFailed;
use App\Domain\Attendance\Events\HolidayCalendarSourceSyncReverted;
use App\Domain\Attendance\Events\HolidayCalendarSourceUpdated;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyCalendarDaySource;
use App\Models\HolidayCalendarEvent;
use App\Models\HolidayCalendarSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * holiday_calendar_source.*イベントからholiday_calendar_sources / holiday_calendar_events /
 * company_calendar_days / company_calendar_day_sourcesを更新する(UC-C012)。
 */
class HolidayCalendarSourceProjector extends Projector
{
    public function __construct(
        private readonly CalendarWeekdayPatternDayGenerator $weekdayPatternDayGenerator,
    ) {}

    public function onHolidayCalendarSourceRegistered(HolidayCalendarSourceRegistered $event): void
    {
        HolidayCalendarSource::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'name' => $event->name,
                'source_kind' => $event->sourceKind,
                'ics_url' => $event->icsUrl,
                'uploaded_ics_path' => $event->uploadedIcsPath,
                'uploaded_ics_filename' => $event->uploadedIcsFilename,
                'sync_status' => HolidayCalendarSource::STATUS_PENDING,
            ],
        );
    }

    /**
     * 名前/参照元を更新するのみ。sync_status/last_synced_at/last_errorはここでは触らない
     * (次回同期をクリックするまで反映しない、既存の同期フローと同じ挙動)。
     */
    public function onHolidayCalendarSourceUpdated(HolidayCalendarSourceUpdated $event): void
    {
        HolidayCalendarSource::query()->whereKey($event->aggregateRootUuid())->update([
            'name' => $event->name,
            'source_kind' => $event->sourceKind,
            'ics_url' => $event->icsUrl,
            'uploaded_ics_path' => $event->uploadedIcsPath,
            'uploaded_ics_filename' => $event->uploadedIcsFilename,
        ]);
    }

    public function onHolidayCalendarSourceSynced(HolidayCalendarSourceSynced $event): void
    {
        $sourceId = $event->aggregateRootUuid();
        $syncedAt = Carbon::now();

        foreach ($event->eventChanges as $change) {
            if ($change['action'] === 'removed') {
                HolidayCalendarEvent::query()
                    ->where('holiday_calendar_source_id', $sourceId)
                    ->where('ics_uid', $change['ics_uid'])
                    ->delete();

                continue;
            }

            $holidayEvent = HolidayCalendarEvent::query()
                ->where('holiday_calendar_source_id', $sourceId)
                ->where('ics_uid', $change['ics_uid'])
                ->first()
                ?? new HolidayCalendarEvent([
                    'id' => (string) Str::uuid(),
                    'holiday_calendar_source_id' => $sourceId,
                    'ics_uid' => $change['ics_uid'],
                ]);

            $holidayEvent->fill(['date' => $change['date'], 'name' => $change['name'], 'synced_at' => $syncedAt])->save();
        }

        foreach ($event->dayChanges as $dayChange) {
            $day = CompanyCalendarDay::query()->find($dayChange['company_calendar_day_id']);
            if ($day === null) {
                continue;
            }

            if ($dayChange['is_public_holiday']) {
                $holidaySchedule = [
                    'day_type' => $day->is_legal_holiday ? 'legal_holiday' : 'company_holiday',
                    'is_working_day' => false,
                    'is_company_holiday' => ! $day->is_legal_holiday,
                    'schedule_state' => CompanyCalendarDay::SCHEDULE_OFF,
                ];
            } else {
                $companyCalendar = $day->year?->companyCalendar;
                $holidaySchedule = $companyCalendar === null ? [] : $this->weekdayPatternDayGenerator->resolveForDate(
                    $day->date->copy(),
                    $companyCalendar->effectiveWeekdayHolidayPattern(),
                );
            }

            $day->update([
                'is_public_holiday' => $dayChange['is_public_holiday'],
                'public_holiday_name' => $dayChange['public_holiday_name'],
                ...$holidaySchedule,
            ]);

            CompanyCalendarDaySource::query()->create([
                'id' => (string) Str::uuid(),
                'company_calendar_day_id' => $day->id,
                'source_type' => 'holiday_sync',
                'source_ref' => (string) $sourceId,
                'applied_at' => $syncedAt,
                'applied_by_user_id' => null,
            ]);
        }

        $actionCounts = ['added' => 0, 'updated' => 0, 'removed' => 0];
        foreach ($event->eventChanges as $change) {
            if (array_key_exists($change['action'], $actionCounts)) {
                $actionCounts[$change['action']]++;
            }
        }

        HolidayCalendarSource::query()->whereKey($sourceId)->update([
            'sync_status' => HolidayCalendarSource::STATUS_SYNCED,
            'last_synced_at' => $syncedAt,
            'last_error' => null,
            'last_sync_summary' => [
                'added' => $actionCounts['added'],
                'updated' => $actionCounts['updated'],
                'removed' => $actionCounts['removed'],
                'applied' => count($event->dayChanges),
                'protected_conflicts' => count($event->protectedConflicts),
            ],
        ]);
    }

    public function onHolidayCalendarSourceSyncFailed(HolidayCalendarSourceSyncFailed $event): void
    {
        HolidayCalendarSource::query()->whereKey($event->aggregateRootUuid())->update([
            'sync_status' => HolidayCalendarSource::STATUS_FAILED,
            'last_error' => $event->error,
        ]);
    }

    public function onHolidayCalendarSourceDisabled(HolidayCalendarSourceDisabled $event): void
    {
        HolidayCalendarSource::query()->whereKey($event->aggregateRootUuid())->update([
            'disabled_at' => Carbon::now(),
        ]);
    }

    public function onHolidayCalendarSourceSyncReverted(HolidayCalendarSourceSyncReverted $event): void
    {
        foreach ($event->dayReverts as $dayRevert) {
            CompanyCalendarDay::query()->whereKey($dayRevert['company_calendar_day_id'])->update([
                'is_public_holiday' => $dayRevert['is_public_holiday'],
                'public_holiday_name' => $dayRevert['public_holiday_name'],
            ]);
        }
    }

    /**
     * 祝日iCalendarソースを削除する。holiday_calendar_eventsはholiday_calendar_source_idへの
     * 外部キーにcascadeOnDeleteが設定されているため、この行を削除するだけで自動的に
     * 削除される。
     */
    public function onHolidayCalendarSourceDeleted(HolidayCalendarSourceDeleted $event): void
    {
        HolidayCalendarSource::query()->whereKey($event->aggregateRootUuid())->delete();
    }
}
