<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * holiday_calendar_source.synced (UC-C012 手順2〜3: 祝日iCalendarソースを同期する)。
 * 集約ID(holiday_calendar_sources.id)は`aggregateRootUuid()`から取得する。
 *
 * 同期1回分で行った変更をすべてこのイベントにまとめて記録する
 * (`company_calendar_days`は一切変更しない「取得・パース失敗」とは別イベントに分ける)。
 */
class HolidayCalendarSourceSynced extends ShouldBeStored
{
    /**
     * @param  list<array{ics_uid: string, date: string, name: string, action: string}>  $eventChanges  holiday_calendar_eventsへの差分反映内容(action: added/updated/removed)
     * @param  list<array{company_calendar_day_id: int, date: string, is_public_holiday: bool, public_holiday_name: ?string, previous_is_public_holiday: bool, previous_public_holiday_name: ?string}>  $dayChanges  実際にis_public_holiday等を更新したcompany_calendar_days。previous_*は同期直前の値(RevertLastHolidayCalendarSyncでの取消用)。
     * @param  list<array{company_calendar_day_id: int, date: string}>  $protectedConflicts  手動変更保護のため自動上書きしなかった対象
     */
    public function __construct(
        public readonly array $eventChanges,
        public readonly array $dayChanges,
        public readonly array $protectedConflicts,
        public readonly ?string $syncedByUserId,
    ) {}
}
